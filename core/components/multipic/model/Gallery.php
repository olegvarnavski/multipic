<?php
/** Pure JSON contract and filtering, PHP 7.4+. No MODX dependency. */
namespace MultiPic;
final class Gallery
{
    public static function decode($value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || ($value['type'] ?? '') !== 'multipic' || ($value['version'] ?? 0) !== 1 || !isset($value['items']) || !is_array($value['items'])) {
            throw new \InvalidArgumentException('Некорректный формат MultiPic');
        }
        if (count($value['items']) > 1000) {
            throw new \InvalidArgumentException('Слишком много изображений');
        }
        return $value;
    }
    public static function select($value, string $parity = 'all', int $offset = 0, int $limit = 0): array
    {
        if (!in_array($parity, ['all', 'odd', 'even'], true)) {
            throw new \InvalidArgumentException('parity: all, odd или even');
        }
        $gallery = self::decode($value);
        $visible = []; $idx = 0;
        foreach ($gallery['items'] as $item) {
            if (!is_array($item) || empty($item['active'])) { continue; }
            $idx++;
            $item['idx'] = $idx;
            $item['odd'] = $idx % 2 === 1; $item['even'] = !$item['odd'];
            if ($parity !== 'all' && !$item[$parity]) { continue; }
            $visible[] = $item;
        }
        $result = array_slice($visible, max(0, $offset), $limit > 0 ? $limit : null);
        foreach ($result as $i => &$item) {
            $item['iteration'] = $i + 1;
            $item['first'] = $i === 0; $item['last'] = $i === count($result) - 1;
        }
        unset($item);
        return $result;
    }
    /** MIGX can contain JSON both as nested objects and serialized field strings. */
    public static function collect($value, int $depth = 0): array
    {
        if ($depth > 32) { throw new \RuntimeException('Слишком глубокая структура MIGX'); }
        if (is_string($value)) {
            $trim = ltrim($value);
            if ($trim === '' || !in_array($trim[0], ['{', '['], true)) { return []; }
            $decoded = json_decode($value, true, 64);
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (strpos($value, 'multipic') !== false) { throw new \RuntimeException('Повреждены данные MultiPic'); }
                return [];
            }
            return self::collect($decoded, $depth + 1);
        }
        if (!is_array($value)) { return []; }
        if (($value['type'] ?? '') === 'multipic') { return [self::decode($value)]; }
        $out = [];
        foreach ($value as $child) { $out = array_merge($out, self::collect($child, $depth + 1)); }
        return $out;
    }
    public static function folder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder === '' || !preg_match('~^[a-zA-Z0-9_/-]+$~D', $folder) || preg_match('~(^|/)\.{1,2}(/|$)~', $folder)) {
            throw new \InvalidArgumentException('Некорректная папка загрузки');
        }
        return preg_replace('~/+~', '/', $folder) . '/';
    }
}
