<?php
declare(strict_types=1);

/**
 * Generate executable tool pages only for explicitly approved specifications.
 * This step never changes the active registry or sitemap and never publishes.
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/generate-approved-tools.php <specs.json> <approvals.json> [output-dir]\n");
    exit(2);
}
$specFile = $argv[1];
$approvalFile = $argv[2];
$outputDir = $argv[3] ?? dirname(__DIR__) . '/generated-tools';
if (!is_file($specFile) || !is_file($approvalFile)) { fwrite(STDERR, "Input file missing.\n"); exit(1); }
$specs = json_decode((string)file_get_contents($specFile), true);
$approvals = json_decode((string)file_get_contents($approvalFile), true);
if (!is_array($specs) || !is_array($specs['specifications'] ?? null) || !is_array($approvals) || !is_array($approvals['approvals'] ?? null)) { fwrite(STDERR, "Invalid generator input.\n"); exit(1); }

$approved = [];
foreach ($approvals['approvals'] as $item) {
    if (!is_array($item)) continue;
    $slug = strtolower(trim((string)($item['slug'] ?? '')));
    if ($slug !== '' && ($item['approved'] ?? false) === true) $approved[$slug] = $item;
}
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) { fwrite(STDERR, "Unable to create output directory.\n"); exit(1); }

function html_shell(string $title, string $description, string $body, string $script): string {
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n<title>" . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . "</title>\n<meta name=\"description\" content=\"" . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . "\">\n<link rel=\"canonical\" href=\"https://junctiontools.com/" . rawurlencode(strtolower((string)preg_replace('/[^a-z0-9]+/i','-',trim($title)))) . "\">\n</head>\n<body>\n<main>\n" . $body . "\n</main>\n<script>\n" . $script . "\n</script>\n</body>\n</html>\n";
}

$generated = 0;
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) continue;
    $tool = $spec['tool'] ?? [];
    $slug = strtolower(trim((string)($tool['slug'] ?? '')));
    if ($slug === '' || !isset($approved[$slug])) continue;
    if (($spec['spec_status'] ?? '') !== 'draft' || ($spec['generation_eligible'] ?? true) !== false) { fwrite(STDERR, "Refusing non-draft or generation-authorized spec: {$slug}\n"); exit(1); }

    $name = (string)($tool['name'] ?? ucwords(str_replace('-', ' ', $slug)));
    $description = (string)($spec['seo']['description'] ?? ('Free ' . $name . ' tool.'));
    $title = $name . ' | Free Online Tool | JunctionTools';

    if ($slug === 'age-calculator') {
        $body = '<h1>Age Calculator</h1><p>Calculate exact age in years, months and days.</p><label for="birthDate">Date of Birth</label><input id="birthDate" type="date"><label for="asOfDate">Calculate Age On</label><input id="asOfDate" type="date"><button id="calculate" type="button">Calculate Age</button><button id="reset" type="button">Reset</button><section id="result" aria-live="polite"></section>';
        $script = <<<'JS'
(() => {
  const birth = document.getElementById('birthDate');
  const asOf = document.getElementById('asOfDate');
  const result = document.getElementById('result');
  asOf.value = new Date().toISOString().slice(0, 10);
  function ageCalculator() {
    result.textContent = '';
    if (!birth.value || !asOf.value) { result.textContent = 'Please select both dates.'; return; }
    const b = new Date(birth.value + 'T00:00:00');
    const a = new Date(asOf.value + 'T00:00:00');
    if (Number.isNaN(b.getTime()) || Number.isNaN(a.getTime())) { result.textContent = 'Please enter valid dates.'; return; }
    if (b > a) { result.textContent = 'Date of birth cannot be after the calculation date.'; return; }
    let years = a.getFullYear() - b.getFullYear();
    let months = a.getMonth() - b.getMonth();
    let days = a.getDate() - b.getDate();
    if (days < 0) { months--; const previousMonth = new Date(a.getFullYear(), a.getMonth(), 0); days += previousMonth.getDate(); }
    if (months < 0) { years--; months += 12; }
    const totalDays = Math.floor((Date.UTC(a.getFullYear(), a.getMonth(), a.getDate()) - Date.UTC(b.getFullYear(), b.getMonth(), b.getDate())) / 86400000);
    result.textContent = `${years} years, ${months} months, ${days} days (${totalDays} total days).`;
  }
  document.getElementById('calculate').addEventListener('click', ageCalculator);
  document.getElementById('reset').addEventListener('click', () => { birth.value = ''; asOf.value = new Date().toISOString().slice(0, 10); result.textContent = ''; });
})();
JS;
    } else {
        $body = '<h1>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h1><label for="input">Input</label><textarea id="input" rows="6"></textarea><button id="run" type="button">Run</button><button id="reset" type="button">Reset</button><output id="result"></output>';
        $script = <<<'JS'
(() => {
  const input = document.getElementById('input'), result = document.getElementById('result');
  document.getElementById('run').addEventListener('click', () => { result.textContent = input.value.trim() ? input.value.trim() : 'Please enter a value.'; });
  document.getElementById('reset').addEventListener('click', () => { input.value = ''; result.textContent = ''; });
})();
JS;
    }
    $html = html_shell($title, $description, $body, $script);
    $path = rtrim($outputDir, '/\\') . '/' . $slug . '.html';
    if (file_put_contents($path, $html, LOCK_EX) === false) { fwrite(STDERR, "Unable to write {$path}.\n"); exit(1); }
    $generated++;
}

echo 'Generated ' . $generated . ' approved tool page(s). No registry or sitemap changes were made.\n';
