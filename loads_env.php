<?php
function loadEnv(string $path = ''): void {
    if ($path === '') {
        $path = __DIR__ . '/.env';
    }
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);
        // Remove inline comment (e.g. VALUE=foo # comment)
        if (($pos = strpos($value, ' #')) !== false) {
            $value = rtrim(substr($value, 0, $pos));
        }
        // Strip surrounding quotes
        if (strlen($value) >= 2 && (
            ($value[0] === '"'  && $value[-1] === '"')  ||
            ($value[0] === "'"  && $value[-1] === "'")
        )) {
            $value = substr($value, 1, -1);
        }
        putenv("$name=$value");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}
