<?php
/**
 * Categorizer – assigns each transaction to a category by matching the details text against
 * each category's keywords (separated by "|"). Categories are checked in order of category_id,
 * so the first match wins.
 */
class Categorizer
{
    private array $patterns = [];   // category_id => compiled regular expression

    public function __construct(PDO $db)
    {
        // Load every category in order and prepare its pattern once, before categorising many transactions.
        foreach ($db->query('SELECT category_id, keyword_pattern FROM categories ORDER BY category_id') as $c) {
            $regex = self::compile($c['keyword_pattern']);
            if ($regex !== null) {
                $this->patterns[(int) $c['category_id']] = $regex;
            }
        }
    }

    /** Turns "Pay Bill|Paybill" into a safe, case-insensitive regular expression. */
    public static function compile(string $keywords): ?string
    {
        $parts = array_filter(array_map('trim', explode('|', $keywords)), 'strlen');
        if (!$parts) {
            return null;
        }
        // preg_quote() makes characters such as "." or "(" in a keyword match literally. The "i" ignores upper/lower case.
        return '/' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $parts)) . '/i';
    }

    public function categorize(string $details): ?int
    {
        // Try the categories in order; the first one whose keywords appear in the details wins.
        foreach ($this->patterns as $id => $regex) {
            if (preg_match($regex, $details)) {
                return $id;
            }
        }
        return null;                // no keyword matched: the transaction stays Uncategorised
    }
}
