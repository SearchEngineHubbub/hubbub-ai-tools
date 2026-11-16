<?php
define('HUBBUB_AI_HUB_TEST', true);
$P = __DIR__ . '/../hubbub-ai-hub/';
require $P.'includes/class-robots.php';
require $P.'includes/class-checker.php';
$data = json_decode(file_get_contents($P.'data/crawlers.json'), true);
$fail=0; function ok($c,$m){global $fail; echo ($c?'PASS ':'FAIL ').$m."\n"; if(!$c)$fail++;}

// Robots tests
$r = new Hubbub_AI_Hub_Robots("User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/\nAllow: /private/ok\n\nUser-agent: PerplexityBot\nDisallow: /*.pdf$\n");
ok(!$r->check('GPTBot','/')['allowed'], 'GPTBot blocked');
ok(!$r->check('ccbot','/x')['allowed'], 'CCBot blocked case-insensitive');
ok($r->check('ClaudeBot','/')['allowed'], 'ClaudeBot falls to * allowed');
ok(!$r->check('ClaudeBot','/private/x')['allowed'], '* disallow private');
ok($r->check('ClaudeBot','/private/ok')['allowed'], 'longest allow wins');
ok($r->check('PerplexityBot','/private/x')['allowed'], 'specific group ignores *');
ok(!$r->check('PerplexityBot','/a.pdf')['allowed'], 'wildcard $');
ok($r->check('PerplexityBot','/a.pdfx')['allowed'], 'anchor works');
$e = new Hubbub_AI_Hub_Robots('');
ok($e->check('GPTBot','/')['allowed'], 'empty allows');

// Checker with fixtures
$good = '<html><head><title>Guide</title><meta name="description" content="A guide"><meta name="author" content="Dan"><link rel="canonical" href="https://ex.com/p"><meta property="article:modified_time" content="2026-01-01"><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Article"},{"@type":"Organization"}]}</script></head><body><h1>T</h1><h2>a</h2><h2>b</h2><p>'.str_repeat('word ', 400).'</p></body></html>';
$sites = [
 'https://ex.com/p' => ['code'=>200,'body'=>$good],
 'https://ex.com/robots.txt' => ['code'=>200,'body'=>"User-agent: GPTBot\nDisallow: /\n"],
 'https://ex.com/llms.txt' => ['code'=>200,'body'=>"# Ex\n> sum\n- [A](https://ex.com/a)\n"],
 'https://bad.com/' => ['code'=>200,'body'=>'<html><head><meta name="robots" content="noindex"></head><body><div id=app></div><script>x</script></body></html>'],
 'https://bad.com/robots.txt' => ['code'=>200,'body'=>"User-agent: *\nDisallow: /\n"],
 'https://bad.com/llms.txt' => ['code'=>404,'body'=>'nf'],
];
$fetch = function($u) use ($sites){ $s = $sites[$u] ?? ['code'=>404,'body'=>'']; return $s + ['headers'=>[],'url'=>$u,'error'=>'']; };
$c = new Hubbub_AI_Hub_Checker($data['crawlers'], $fetch);
$g = $c->run('ex.com/p');
ok($g['score'] >= 85, 'good site score '.$g['score']);
$ids = array_column($g['checks'],'status','id');
ok($ids['ai-search-bots']==='pass' && $ids['llms-txt']==='pass' && $ids['schema']==='pass', 'good checks');
ok(strpos(json_encode($g['checks']), 'GPTBot') !== false, 'training block noted');
$b = $c->run('https://bad.com/');
ok($b['score'] <= 40, 'bad site score '.$b['score']);
$ids = array_column($b['checks'],'status','id');
ok($ids['noindex']==='fail' && $ids['ai-search-bots']==='fail' && $ids['server-html']==='fail', 'bad checks');
ok(isset($c->run('not a url')['error']), 'invalid url rejected');
ok(isset((new Hubbub_AI_Hub_Checker([], function($u){return ['code'=>0,'body'=>'','headers'=>[],'url'=>$u,'error'=>'timeout'];}))->run('ex.com')['error']), 'unreachable');
echo json_encode($b['checks'][1], JSON_PRETTY_PRINT)."\n";
// Verify a bot: CIDR matching and list parsing.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__);
require_once $P.'includes/class-verify.php';
ok(Hubbub_AI_Hub_Verify::in_cidr('20.171.207.10', '20.171.206.0/23'), 'ipv4 in /23');
ok(!Hubbub_AI_Hub_Verify::in_cidr('20.171.208.1', '20.171.206.0/23'), 'ipv4 outside /23');
ok(Hubbub_AI_Hub_Verify::in_cidr('66.249.66.1', '66.249.66.1/32'), 'ipv4 /32');
ok(Hubbub_AI_Hub_Verify::in_cidr('2001:4860:4801:10::1', '2001:4860:4801:10::/64'), 'ipv6 in /64');
ok(!Hubbub_AI_Hub_Verify::in_cidr('2001:4860:4801:11::1', '2001:4860:4801:10::/64'), 'ipv6 outside /64');
ok(!Hubbub_AI_Hub_Verify::in_cidr('20.171.207.10', '2001:4860::/32'), 'v4 vs v6 never match');
$pl = Hubbub_AI_Hub_Verify::parse('{"creationTime":"2026-11-01T00:00:00Z","prefixes":[{"ipv4Prefix":"1.2.3.0/24"},{"ipv6Prefix":"2600::/48"},{"x":1}]}');
ok($pl['updated'] === '2026-11-01' && count($pl['prefixes']) === 2, 'parses published list format');
ok(Hubbub_AI_Hub_Verify::parse('<html>')['prefixes'] === [], 'bad list parses empty');
// The shipped stylesheet is built from tools/hub.src.css; it must be current.
require_once $P.'includes/class-interest.php';
$iv = Hubbub_AI_Hub_Interest::parse('{"items":[{"timestamp":"2026090100","views":12},{"timestamp":"2026100100","views":15}]}');
ok($iv === [['202609',12],['202610',15]], 'pageviews parse to [month, views]');
ok(Hubbub_AI_Hub_Interest::parse('{"type":"not_found"}') === [], 'missing article parses empty');
require __DIR__.'/../tools/build-css.php';
ok(hubbub_build_css() === file_get_contents($P.'assets/hub.css'), 'hub.css is built from tools/hub.src.css (run php tools/build-css.php)');
ok(strpos(hubbub_scope_css('.a, .b:not(.c, .d) li { x: 1; } @media (max-width: 9px) { .hubbub-hub .e { y: 2; } } @keyframes k { to { z: 3; } }'), 'html body .hubbub-hub.hubbub-hub.hb-app .b:not(.c, .d) li') !== false, 'scoping keeps :not() lists intact');
echo "failures: $fail\n";
