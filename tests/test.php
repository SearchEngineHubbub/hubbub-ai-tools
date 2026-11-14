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
echo "failures: $fail\n";
