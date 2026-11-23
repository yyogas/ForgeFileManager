<?php
declare(strict_types=1);

namespace ForgeFileManager;

/**
 * Deterministic, server-side directory paging. Selection, filtering and ordering
 * occur before page slicing. No content of other pages is returned to clients.
 * This is an offset-based, non-snapshot listing: concurrent changes may shift rows.
 */
final class DirectoryPager
{
    public const PAGE_SIZE = 25;
    public const SORTS = ['name', 'name-desc', 'date', 'size'];

    /** @param list<array<string, mixed>> $entries
     *  @return array{items:list<array<string, mixed>>,page:int,pages:int,pageSize:int,total:int,hasNext:bool}
     */
    public static function paginate(array $entries, int $page = 1, string $search = '', string $sort = 'name', bool $showHidden = false): array
    {
        if ($page < 1 || $page > 1000000) {
            throw new FileError('Invalid page number.', 400);
        }
        if (!in_array($sort, self::SORTS, true)) {
            throw new FileError('Unsupported directory sort order.', 400);
        }
        if (strlen($search) > 200 || preg_match('//u', $search) !== 1) {
            throw new FileError('Search must be valid UTF-8 and at most 200 bytes.', 400);
        }
        $search = trim($search);
        $matches = array_values(array_filter($entries, static function (array $item) use ($search, $showHidden): bool {
            $name = (string) $item['name'];
            if (!$showHidden && str_starts_with($name, '.')) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            if (function_exists('mb_stripos')) {
                return mb_stripos($name, $search, 0, 'UTF-8') !== false;
            }
            return stripos($name, $search) !== false;
        }));
        usort($matches, static function (array $a, array $b) use ($sort): int {
            // Always present directories first, independent of the selected sort.
            $isDirA = ($a['type'] === 'directory');
            $isDirB = ($b['type'] === 'directory');
            if ($isDirA !== $isDirB) {
                return $isDirA ? -1 : 1;
            }
            if ($sort === 'date') {
                $cmp = ((int) ($b['modified'] ?? 0)) <=> ((int) ($a['modified'] ?? 0));
                if ($cmp !== 0) return $cmp;
            }
            if ($sort === 'size') {
                $cmp = ((int) ($b['size'] ?? 0)) <=> ((int) ($a['size'] ?? 0));
                if ($cmp !== 0) return $cmp;
            }
            $cmp = strnatcasecmp((string) $a['name'], (string) $b['name']);
            if ($cmp === 0) {
                $cmp = strcmp((string) $a['name'], (string) $b['name']);
            }
            return $sort === 'name-desc' ? -$cmp : $cmp;
        });
        $total = count($matches);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $pages);
        $items = array_slice($matches, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        return [
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'pageSize' => self::PAGE_SIZE,
            'total' => $total,
            'hasNext' => $page < $pages,
        ];
    }
}
