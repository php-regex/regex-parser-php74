<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * @internal
 *
 * What a node reads, from the tree alone, for CaptureShapeAnalyzer: its
 * length range, its values when they are a small finite set, and the facts
 * every value satisfies. An answer depends on the node, on the options in
 * force where it stands and on the groups it calls, so each node is
 * measured once, and its answer kept as long as the node lives.
 *
 * Every answer is sound: when the tree alone cannot tell, the values are
 * unknown and the facts are false.
 *
 * @phpstan-import-type LengthRange from LengthRangeCalculator
 *
 * @phpstan-type Facts array{min: int, max: int|null, values: list<string>|null, nonFalsy: bool, digitsOnly: bool}
 */
final class CaptureFacts
{
    /**
     * Past this many strings, the values are unknown.
     */
    public const MAX_VALUES = 32;

    /**
     * \D, \W, \s, \h, \v and \R never match "0".
     */
    private const ZERO_FREE_TYPES = ['D', 'W', 's', 'h', 'v', 'R'];

    /**
     * POSIX classes that never hold "0", whatever the case.
     */
    private const ZERO_FREE_POSIX = ['alpha', 'upper', 'lower', 'space', 'blank', 'cntrl', 'punct'];

    /**
     * @var bool
     *
     */
    private $unicode = false;

    /**
     * @var bool
     *
     */
    private $ucp = false;

    /**
     * @var GroupIndex|null
     *
     */
    private $groups;

    /**
     *
     * @var \PHPRegex\Parser\Analysis\LengthRangeCalculator
     */
    private $calculator;

    /**
     * @var \SplObjectStorage<NodeInterface, LengthRange>
     */
    private $lengths;

    /**
     * @var \SplObjectStorage<NodeInterface, Facts>
     *
     */
    private $facts;

    /**
     * @var \SplObjectStorage<NodeInterface, Facts>
     *
     */
    private $groupFacts;

    /**
     * Characters and classes the engine reads caselessly, by object id: a
     * value that may fold is not read there.
     *
     * @var array<int, true>
     */
    private $caseless = [];

    /**
     * Groups whose body is read through a call, by object id: a call met
     * again while its group is read is a recursion.
     *
     * @var array<int, true>
     */
    private $calling = [];

    /**
     * Nodes the scan reaches before any part of the pattern may have read
     * a character, by object id: an alternation there is read at the start.
     *
     * @var array<int, true>
     */
    private $atStart = [];

    /**
     * Nodes nothing in the pattern follows, by object id: an alternation
     * there is not left by backtracking from a later part that fails.
     *
     * @var array<int, true>
     */
    private $atEnd = [];

    /**
     * @param bool            $unicode lengths count code points, and literals read as UTF-8
     * @param bool            $ucp     \d and [[:digit:]] match non-ASCII digits: /u, or (*UCP)
     * @param GroupIndex|null $groups  the groups of the pattern, which its calls read
     */
    public function __construct(
        bool $unicode = false,
        bool $ucp = false,
        ?GroupIndex $groups = null
    ) {
        $this->unicode = $unicode;
        $this->ucp = $ucp;
        $this->groups = $groups;
        $this->calculator = new LengthRangeCalculator($unicode);
        $this->lengths = new \SplObjectStorage();
        $this->facts = new \SplObjectStorage();
        $this->groupFacts = new \SplObjectStorage();
    }

    /**
     * The length range of what the node reads.
     *
     * @return LengthRange
     */
    public function lengths(NodeInterface $node): array
    {
        if ($node instanceof SubroutineNode) {
            return $this->lengths[$node] ??= $this->called($node, \Closure::fromCallable([$this, 'lengths']), [0, null]);
        }

        return $this->lengths[$node] ??= $node->accept($this->calculator);
    }

    /**
     * What the node reads, as a capture holds it once set: its length range,
     * its values, whether every value is truthy (nonFalsy), and whether
     * every value is a non-empty run of ASCII digits (digitsOnly).
     *
     * A group's facts hold for every match of a scan, so they never take
     * the dominance of a zero-width first branch: a scan resumes after an
     * empty match, where "^" no longer matches.
     *
     * @return Facts
     */
    public function of(NodeInterface $node, bool $group = false): array
    {
        $cache = $group ? $this->groupFacts : $this->facts;
        if (isset($cache[$node])) {
            return $cache[$node];
        }

        [$min, $max] = $this->lengths($node);
        $values = $this->values($node, $group);

        return $cache[$node] = [
            'min' => $min,
            'max' => $max,
            'values' => $values,
            'nonFalsy' => $this->nonFalsy($node, $min, $values),
            // a digit run of any length is digits or the empty string; the
            // readers compose the empty arm with the length themselves
            'digitsOnly' => $this->digitsOnly($node),
        ];
    }

    /**
     * Marks a node the scan reaches before any part of the pattern may have
     * read a character. The analysis collects them before it asks for facts.
     */
    public function markAtStart(NodeInterface $node): void
    {
        $this->atStart[spl_object_id($node)] = true;
    }

    /**
     * Marks a character or a class the engine reads caselessly. The
     * analysis collects them before it asks for facts.
     */
    public function markCaseless(NodeInterface $node): void
    {
        $this->caseless[spl_object_id($node)] = true;
    }

    /**
     * Marks a node nothing in the pattern follows. The analysis collects
     * them before it asks for facts.
     */
    public function markAtEnd(NodeInterface $node): void
    {
        $this->atEnd[spl_object_id($node)] = true;
    }

    /**
     * What the node reads across a whole scan, every branch joined: the
     * dominance above speaks for one match, and a scan that resumes after
     * an empty match still reaches the later branches.
     *
     * @return list<string>|null
     */
    public function scanValues(NodeInterface $node): ?array
    {
        return $this->values($node, true);
    }

    private static function LOOKAROUNDS(): array
    {
        static $cache = null;
        if (null === $cache) {
            $cache = [GroupType::LookaheadPositive(), GroupType::LookaheadNegative(), GroupType::LookbehindPositive(), GroupType::LookbehindNegative()];
        }

        return $cache;
    }

    /**
     * The strings a node can match, when they are a small finite set read
     * from literals alone.
     *
     * @return list<string>|null
     */
    private function values(NodeInterface $node, bool $scan = false): ?array
    {
        switch (true) {
            case $node instanceof LiteralNode:
                return $this->folds($node->value, $this->readsCaselessly($node)) ? null : [$node->value];
            case $node instanceof CharLiteralNode:
                return $this->character($node->codePoint, $this->readsCaselessly($node));
            case $node instanceof SequenceNode:
                return $this->product(array_map(fn (NodeInterface $child): ?array => $this->values($child, $scan), $node->children));
            case $node instanceof AlternationNode:
                return $this->alternationValues($node, $scan);
            case $node instanceof GroupNode:
                return self::isLookaround($node) ? [''] : $this->values($node->child, $scan);
            case $node instanceof QuantifierNode:
                return $this->repeat($node, $scan);
            case $node instanceof CharClassNode:
                return $this->classMembers($node);
            case $node instanceof AnchorNode:
            case $node instanceof AssertionNode:
            case $node instanceof CommentNode:
            case $node instanceof KeepNode:
            case $node instanceof DefineNode:
            case $node instanceof CalloutNode:
                return [''];
            case $node instanceof PcreVerbNode:
                return 0 === strncmp($node->verb, 'ACCEPT', strlen('ACCEPT')) ? null : [''];
            case $node instanceof SubroutineNode:
                return $this->called($node, fn (NodeInterface $body): ?array => $this->values($body, $scan), null);
            default:
                return null;
        }
    }

    /**
     * What a call reads: what the body of the group it calls reads, with the
     * options of that group. A call of the whole pattern or of no group, or
     * one met again while its group is read, gives $unknown.
     *
     * @template T
     *
     * @param callable(NodeInterface): T $read
     *
     * @return T
     */
    private function called(SubroutineNode $call, callable $read, $unknown)
    {
        $group = (($nullsafeVariable1 = $this->groups) ? $nullsafeVariable1->groupsCalledBy($call) : null)[0] ?? null;
        if (null === $group || isset($this->calling[$id = spl_object_id($group)])) {
            return $unknown;
        }

        $this->calling[$id] = true;

        try {
            return $read($group->child);
        } finally {
            unset($this->calling[$id]);
        }
    }

    private function readsCaselessly(NodeInterface $node): bool
    {
        return isset($this->caseless[spl_object_id($node)]);
    }

    /**
     * The branches' values — or the empty string alone, when the alternation
     * sits at the start of the pattern, nothing follows it, and its first
     * branch is a zero-width anchor that wins the match before any later
     * branch is ever reached: the engine reads the branches left to right at
     * each position and takes the leftmost, and "^" and "\b" read empty at
     * the scan start. Anything after the alternation could fail and send
     * the engine back into the later branches: /(^|a)b/ matches "ab".
     *
     * @return list<string>|null
     */
    private function alternationValues(AlternationNode $node, bool $scan = false): ?array
    {
        $id = spl_object_id($node);
        if (!$scan && ($this->atStart[$id] ?? false) && ($this->atEnd[$id] ?? false) && $this->dominatesFromStart($node->alternatives)) {
            return [''];
        }

        return $this->union(array_map(fn (NodeInterface $branch): ?array => $this->values($branch, $scan), $node->alternatives));
    }

    /**
     * Whether a first branch of "^" or "\A" — which matches at the scan
     * start whatever the subject holds — or of "\b", which matches at or
     * before wherever a later branch starting with a word character can
     * begin, keeps the later branches from ever filling the match.
     *
     * @param list<NodeInterface> $alternatives
     */
    private function dominatesFromStart(array $alternatives): bool
    {
        $first = $alternatives[0] ?? null;

        if ($first instanceof AnchorNode && ('^' === $first->value || '\\A' === $first->value)) {
            return true;
        }

        if (!$first instanceof AssertionNode || 'b' !== $first->value) {
            return false;
        }

        foreach (\array_slice($alternatives, 1) as $alternative) {
            $char = $this->firstConsumedChar($alternative);
            if (null === $char || !self::isWordChar($char)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first character a node may read, when it starts with a literal
     * one; null for anything else.
     */
    private function firstConsumedChar(NodeInterface $node): ?string
    {
        if ($node instanceof LiteralNode) {
            return '' === $node->value ? null : $node->value[0];
        }

        if ($node instanceof CharLiteralNode) {
            $chars = $this->character($node->codePoint, $this->readsCaselessly($node));

            return null === $chars ? null : $chars[0];
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                if (0 !== ($this->lengths($child)[1] ?? 1)) {
                    return $this->firstConsumedChar($child);
                }
            }

            return null;
        }

        if ($node instanceof GroupNode && !\in_array($node->type, self::LOOKAROUNDS(), true)) {
            return $this->firstConsumedChar($node->child);
        }

        return null;
    }

    private static function isWordChar(string $char): bool
    {
        return ($char >= '0' && $char <= '9')
            || ($char >= 'A' && $char <= 'Z')
            || ($char >= 'a' && $char <= 'z')
            || '_' === $char;
    }

    /**
     * The members a class holds, when each of them is a small finite set of
     * characters the pattern spells: literals and short ranges. A member too
     * big to spell — a type, a property, a long range, a negated class —
     * leaves the whole class unread, as it always did, and so does one that
     * may fold under caseless matching.
     *
     * @return list<string>|null
     */
    private function classMembers(CharClassNode $node): ?array
    {
        if ($node->isNegated) {
            return null;
        }

        $caseless = $this->readsCaselessly($node);
        $members = $node->expression instanceof AlternationNode
            ? $node->expression->alternatives
            : [$node->expression];

        $values = [];
        foreach ($members as $member) {
            if ($member instanceof RangeNode) {
                $from = $this->codePointOf($member->start);
                $to = $this->codePointOf($member->end);
                // the parser refuses a reversed range; a member the code
                // point of which it does not spell — a type, a property —
                // leaves the class unread
                if (null === $from || null === $to || $to < $from || $to - $from + 1 > self::MAX_VALUES) {
                    return null;
                }

                for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
                    $character = $this->character($codePoint, $caseless);
                    if (null === $character) {
                        return null;
                    }

                    $values[] = $character[0];
                }

                continue;
            }

            if ($member instanceof LiteralNode) {
                // the pattern never writes an empty literal into a class;
                // a reader that hands one in enumerates no member, and an
                // empty enumeration reads as no constant downstream. Without
                // /u a class matches one byte: [é] matches "\xc3" and "\xa9"
                $characters = $this->unicode ? mb_str_split($member->value, 1, 'UTF-8') : Bytes::split($member->value);
                foreach ($characters as $character) {
                    if ($this->folds($character, $caseless)) {
                        return null;
                    }

                    $values[] = $character;
                }

                continue;
            }

            // anything else a class holds — a type, a POSIX class, a
            // property — spells a set too large to enumerate
            $memberCodePoint = $this->codePointOf($member);
            // a code point no character of the encoding spells — a
            // surrogate under /u, past 255 without it — leaves the class unread
            $character = null === $memberCodePoint ? null : $this->character($memberCodePoint, $caseless);
            if (null === $character) {
                return null;
            }

            $values[] = $character[0];
        }

        if (\count($values) > self::MAX_VALUES) {
            return null;
        }

        return $this->union([$values]);
    }

    /**
     * The code point of a member a class spells by a single character,
     * whatever the spelling; null for every other member.
     */
    private function codePointOf(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if ($node instanceof ControlCharNode) {
            return $node->codePoint;
        }

        if ($node instanceof LiteralNode && 1 === mb_strlen($node->value, 'UTF-8')) {
            $codePoint = mb_ord($node->value, 'UTF-8');

            return false === $codePoint ? null : $codePoint;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function repeat(QuantifierNode $node, bool $scan = false): ?array
    {
        $bounds = QuantifierBounds::parse($node->quantifier);
        $body = $this->values($node->node, $scan);
        if (null === $bounds || null === $body || null === $bounds->max) {
            return null;
        }

        // A body that reads nothing reads nothing at any count.
        if ([''] === $body) {
            return [''];
        }

        // Each count adds a string longer than any before: past MAX_VALUES counts, the values are unknown.
        if ($bounds->max > self::MAX_VALUES) {
            return null;
        }

        // The body read once per count, from the least to the most; lazy or
        // possessive, the quantifier still stops at one of these counts.
        $values = 0 === $bounds->min ? [''] : [];
        $power = [''];
        for ($count = 1; $count <= $bounds->max; $count++) {
            $power = $this->product([$power, $body]);
            $values = $count < $bounds->min || null === $power ? $values : $this->union([$values, $power]);
            if (null === $power || null === $values) {
                return null;
            }
        }

        return $values;
    }

    /**
     * @return list<string>|null
     */
    private function character(int $codePoint, bool $caseless): ?array
    {
        if ($caseless && $this->foldsCodePoint($codePoint)) {
            return null;
        }

        if ($this->unicode) {
            $character = mb_chr($codePoint, 'UTF-8');

            return false === $character ? null : [$character];
        }

        return $codePoint <= 0xFF ? [\chr($codePoint)] : null;
    }

    /**
     * Whether caseless matching may fold a code point into another: an
     * ASCII letter always folds — preg_match('/(ab£)/i', 'AB£') matches —
     * and without /u nothing else does — preg_match('/(é)/i', 'É') is 0 —
     * while under /u the engine folds by Unicode — preg_match('/é/iu',
     * 'É') is 1 — where past 7 bits nothing is provable.
     */
    private function foldsCodePoint(int $codePoint): bool
    {
        if (self::isAsciiLetter($codePoint)) {
            return true;
        }

        return $this->unicode && $codePoint >= 0x80;
    }

    /**
     * Whether any byte of a string read caselessly may fold, by the code
     * point of each of them: past 7 bits, a byte is a code point in its own
     * right without /u, and a piece of one under it.
     */
    private function folds(string $value, bool $caseless): bool
    {
        if (!$caseless) {
            return false;
        }

        for ($i = 0, $n = \strlen($value); $i < $n; $i++) {
            if ($this->foldsCodePoint(\ord($value[$i]))) {
                return true;
            }
        }

        return false;
    }

    private static function isAsciiLetter(int $codePoint): bool
    {
        return ($codePoint >= 0x41 && $codePoint <= 0x5A) || ($codePoint >= 0x61 && $codePoint <= 0x7A);
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function product(array $parts): ?array
    {
        $strings = [''];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $next = [];
            foreach ($strings as $prefix) {
                foreach ($part as $suffix) {
                    $next[] = $prefix.$suffix;
                }
            }

            $strings = array_values(array_unique($next));
            if (\count($strings) > self::MAX_VALUES) {
                return null;
            }
        }

        return $strings;
    }

    /**
     * @param array<list<string>|null> $parts
     *
     * @return list<string>|null
     */
    private function union(array $parts): ?array
    {
        $strings = [];
        foreach ($parts as $part) {
            if (null === $part) {
                return null;
            }

            $strings = array_merge($strings, $part);
        }

        $strings = array_values(array_unique($strings));

        return \count($strings) > self::MAX_VALUES ? null : $strings;
    }

    /**
     * Whether every value is truthy: neither '' nor '0'.
     *
     * @param list<string>|null $values
     */
    private function nonFalsy(NodeInterface $node, int $min, ?array $values): bool
    {
        if (null !== $values) {
            return !\in_array('', $values, true) && !\in_array('0', $values, true);
        }

        return $min >= 2 || (1 === $min && !$this->mayBeZero($node));
    }

    /**
     * Whether the node may match the string "0" alone. True when unsure.
     */
    private function mayBeZero(NodeInterface $node): bool
    {
        switch (true) {
            case $node instanceof SubroutineNode:
                return $this->called($node, \Closure::fromCallable([$this, 'mayBeZero']), true);
            case $node instanceof CharClassNode:
                return $node->isNegated ? !$this->holdsZero($node->expression) : $this->classMayHoldZero($node->expression);
            case $node instanceof SequenceNode:
                return $this->sequenceMayBeZero($node->children);
            case $node instanceof AlternationNode:
                return self::any($node->alternatives, \Closure::fromCallable([$this, 'mayBeZero']));
            case $node instanceof GroupNode:
                return !self::isLookaround($node) && $this->mayBeZero($node->child);
            case $node instanceof QuantifierNode:
                return 0 !== (($nullsafeVariable2 = QuantifierBounds::parse($node->quantifier)) ? $nullsafeVariable2->max : null) && $this->mayBeZero($node->node);
            case self::consumesNothing($node):
                return false;
            default:
                return $this->readsZero($node, false) ?? true;
        }
    }

    /**
     * Whether a concatenation may read "0" alone: some child reads it while
     * every other child reads nothing — a mandatory character anywhere else
     * pins the value off zero.
     *
     * @param list<NodeInterface> $children
     */
    private function sequenceMayBeZero(array $children): bool
    {
        foreach ($children as $candidate) {
            if (!$this->mayBeZero($candidate)) {
                continue;
            }

            $pinned = false;
            foreach ($children as $child) {
                if ($child !== $candidate && $this->lengths($child)[0] >= 1) {
                    $pinned = true;

                    break;
                }
            }

            if (!$pinned) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a class member may hold "0". True when unsure.
     */
    private function classMayHoldZero(NodeInterface $member): bool
    {
        switch (true) {
            case $member instanceof AlternationNode:
                return self::any($member->alternatives, \Closure::fromCallable([$this, 'classMayHoldZero']));
            case $member instanceof RangeNode:
                return null === ($range = $this->range($member)) || ($range[0] <= 0x30 && 0x30 <= $range[1]);
            case $member instanceof PosixClassNode:
                return !\in_array($member->class, self::ZERO_FREE_POSIX, true);
            default:
                return $this->readsZero($member, false) ?? true;
        }
    }

    /**
     * Whether a class member surely holds "0". False when unsure.
     */
    private function holdsZero(NodeInterface $member): bool
    {
        if ($member instanceof AlternationNode) {
            return self::any($member->alternatives, \Closure::fromCallable([$this, 'holdsZero']));
        }

        return $this->readsZero($member, true) ?? false;
    }

    /**
     * Whether a character or a shorthand reads "0": surely, or possibly.
     * Null for any other node.
     */
    private function readsZero(NodeInterface $atom, bool $surely): ?bool
    {
        switch (true) {
            case $atom instanceof LiteralNode:
            case $atom instanceof CharLiteralNode:
                return 0x30 === $this->codePoint($atom);
            case $atom instanceof CharTypeNode:
                return $surely ? 'd' === $atom->value : !\in_array($atom->value, self::ZERO_FREE_TYPES, true);
            default:
                return null;
        }
    }

    /**
     * Whether every character the node reads is an ASCII digit. No case
     * folds into 0-9; under UCP \d and [[:digit:]] read other digits too.
     * False when unsure.
     */
    private function digitsOnly(NodeInterface $node): bool
    {
        switch (true) {
            case $node instanceof LiteralNode:
                return '' === $node->value || Ascii::isDigit($node->value);
            case $node instanceof CharLiteralNode:
            case $node instanceof CharTypeNode:
                return $this->isDigitMember($node);
            case $node instanceof CharClassNode:
                return !$node->isNegated && $this->isDigitMember($node->expression);
            case $node instanceof SequenceNode:
                return self::all($node->children, \Closure::fromCallable([$this, 'digitsOnly']));
            case $node instanceof AlternationNode:
                return self::all($node->alternatives, \Closure::fromCallable([$this, 'digitsOnly']));
            case $node instanceof GroupNode:
                return self::isLookaround($node) || $this->digitsOnly($node->child);
            case $node instanceof QuantifierNode:
                return $this->digitsOnly($node->node);
            case $node instanceof SubroutineNode:
                return $this->called($node, \Closure::fromCallable([$this, 'digitsOnly']), false);
            default:
                return self::consumesNothing($node);
        }
    }

    /**
     * Whether a class member, or a single character, holds ASCII digits only.
     */
    private function isDigitMember(NodeInterface $member): bool
    {
        switch (true) {
            case $member instanceof AlternationNode:
                return self::all($member->alternatives, \Closure::fromCallable([$this, 'isDigitMember']));
            case $member instanceof LiteralNode:
            case $member instanceof CharLiteralNode:
                return self::isDigit($this->codePoint($member));
            case $member instanceof RangeNode:
                return null !== ($range = $this->range($member)) && self::isDigit($range[0]) && self::isDigit($range[1]);
            case $member instanceof CharTypeNode:
                return 'd' === $member->value && !$this->ucp;
            case $member instanceof PosixClassNode:
                return 'digit' === $member->class && !$this->ucp;
            default:
                return false;
        }
    }

    private static function isDigit(?int $codePoint): bool
    {
        return null !== $codePoint && $codePoint >= 0x30 && $codePoint <= 0x39;
    }

    /**
     * The code points a range spans, when both ends are single characters.
     *
     * @return array{0: int, 1: int}|null
     */
    private function range(RangeNode $range): ?array
    {
        $start = $this->codePoint($range->start);
        $end = $this->codePoint($range->end);

        return null === $start || null === $end ? null : [$start, $end];
    }

    /**
     * The code point of a node that reads one character, or null.
     */
    private function codePoint(NodeInterface $node): ?int
    {
        if ($node instanceof CharLiteralNode) {
            return $node->codePoint;
        }

        if (!$node instanceof LiteralNode || '' === $node->value) {
            return null;
        }

        if (!$this->unicode) {
            return 1 === \strlen($node->value) ? \ord($node->value) : null;
        }

        if (1 !== mb_strlen($node->value, 'UTF-8')) {
            return null;
        }

        $codePoint = mb_ord($node->value, 'UTF-8');

        return false === $codePoint ? null : $codePoint;
    }

    private static function isLookaround(GroupNode $node): bool
    {
        return \in_array($node->type, self::LOOKAROUNDS(), true);
    }

    /**
     * Whether the node reads no character: an anchor, an assertion, a verb.
     */
    private static function consumesNothing(NodeInterface $node): bool
    {
        return $node instanceof AnchorNode || $node instanceof AssertionNode || $node instanceof CommentNode
            || $node instanceof KeepNode || $node instanceof DefineNode || $node instanceof CalloutNode
            || $node instanceof PcreVerbNode || $node instanceof LimitMatchNode;
    }

    /**
     * array_any() of PHP 8.4.
     *
     * @param array<NodeInterface>          $nodes
     * @param callable(NodeInterface): bool $test
     */
    private static function any(array $nodes, callable $test): bool
    {
        foreach ($nodes as $node) {
            if ($test($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_all() of PHP 8.4.
     *
     * @param array<NodeInterface>          $nodes
     * @param callable(NodeInterface): bool $test
     */
    private static function all(array $nodes, callable $test): bool
    {
        foreach ($nodes as $node) {
            if (!$test($node)) {
                return false;
            }
        }

        return true;
    }
}
