<?php
declare(strict_types=1);

function department_card(array $d): string
{
    $labs = '';
    foreach (json_decode($d['labs'], true) as $lab) $labs .= '<li>' . e($lab) . '</li>';
    return '<article class="dept-card reveal" id="' . e($d['id']) . '" data-group="' . e($d['category']) . '">
        <div class="img"><img src="/' . e($d['image']) . '" alt="' . e($d['name']) . '" loading="lazy"><span class="code">' . e($d['code']) . '</span></div>
        <div class="body"><h3>' . e($d['name']) . '</h3><p class="bn">' . e($d['name_bn']) . '</p><p>' . e($d['description']) . '</p>
        <ul class="labs">' . $labs . '</ul><p><strong>Careers:</strong> ' . e($d['careers']) . '</p>
        <div class="foot"><span><b>' . e($d['seats']) . '</b> seats / year</span><span>2 shifts · 8 semesters</span></div></div></article>';
}
function public_page(string $html, string $file): string
{
    $departments = database()->query('SELECT * FROM departments ORDER BY position')->fetchAll();
    $links = ''; $cards = ''; $rows = ''; $total = 0;
    foreach ($departments as $d) {
        $links .= '<a href="departments.html#' . e($d['id']) . '">' . e($d['name']) . ' <span>' . e($d['code']) . '</span></a>';
        $cards .= department_card($d);
        $rows .= '<tr><td>' . e($d['name']) . '</td><td>' . e($d['code']) . '</td><td>' . $d['seats'] / 2 . '</td><td>' . $d['seats'] / 2 . '</td><td>' . e($d['seats']) . '</td></tr>';
        $total += $d['seats'];
        if ($file === 'index.html') {
            $html = preg_replace_callback('~<a class="tech-row[^\"]*" href="departments.html#' . preg_quote($d['id'], '~') . '"[^>]*>.*?</a>~s', function ($match) use ($d) {
                $row = preg_replace_callback('~<h3>.*?</h3>~s', fn() => '<h3>' . e($d['name']) . '<small>' . e($d['name_bn']) . '</small></h3>', $match[0]);
                return preg_replace_callback('~<b>\d+</b>~', fn() => '<b>' . e($d['seats']) . '</b>', $row);
            }, $html);
        }
    }
    $html = preg_replace_callback('~<div class="dropdown">.*?</div>~s', fn() => '<div class="dropdown">' . $links . '</div>', $html);
    return strtr($html, ['{{DEPARTMENT_CARDS}}' => $cards, '{{DEPARTMENT_ROWS}}' => $rows,
        '{{DEPARTMENT_TOTAL}}' => '<tr><td>Total</td><td></td><td>' . $total / 2 . '</td><td>' . $total / 2 . '</td><td>' . number_format($total) . '</td></tr>']);
}
function icon(string $name): string
{
    $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'messages' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'departments' => '<path d="M4 21V7l8-4 8 4v14M2 21h20M9 21v-6h6v6M8 8h1m6 0h1M8 11h1m6 0h1"/>',
        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'logout' => '<path d="M9 4H4v16h5m5-13 5 5-5 5M8 12h11"/>',
        'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['overview']) . '</svg>';
}
function status_badge(string $status): string { return '<span class="badge badge-' . e($status) . '">' . e(ucfirst($status)) . '</span>'; }
function display_date(string $date): string { return gmdate('d M Y, H:i', strtotime($date . ' UTC')) . ' UTC'; }
