<?php

error_reporting(E_ALL);

require dirname(__FILE__) . '/../class.tokenparser.php';

$parser = new Template_Parser;

$t = '$bla->test($foo)|miam:"bla blu blou $t"|escape:Truc::getInstance()->miam( $foo )';
$t = '\'miam coucou c"est marrant `$blu`s oh\'';
//$t = 'foo123($foo,$foo->bar(),"foo")';
//$t = '$foo|bar';

$result = $parser->parseArgumentContent($t);
var_dump($result);

exit;

$args = 'first="Bla::`$blou`" truc="miam coucou c\'est marrant $blu\' oh" miam="ah `$bla|blu`" bla=$bla|blu autre=$a|bb|cat:$miam|escape uh=bla::blou()';

echo '<pre>';

print_r($parser->parseArguments($args));
$parser->parseTokens($args);

/*
$content = '

{literal}

Miam

function ()
{
}

{/literal}

<?xml version="1.0" encoding="UTF-8"?>

';

$tp = new Template_Parser;
echo $tp->Parse($content);*/

?>