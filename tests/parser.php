<?php

require dirname(__FILE__) . '/../class.parser.php';

class Template_Tester extends Template_Parser
{
    public $debug = array();

    public function processString($content)
    {
        $this->debug[] = array('Processing string', $content);
        return parent::processString($content);
    }

    public function processModifier($name, $content, $arguments, $map_array)
    {
        $this->debug[] = array('Processing modifier', $name, $content, $arguments);
        return parent::processModifier($name, $content, $arguments, $map_array);
    }

    public function processVariable($name)
    {
        $this->debug[] = array('Processing variable', $name);
        return parent::processVariable($name);
    }

    public function testArgs($args)
    {
        return $this->parseArguments($args);
    }
}

$test = new Template_Tester;

$args = 'truc="miam $blu\' oh" miam="ah `$bla|blu`" bla=$bla|blu autre=$a|bb|cat:$miam|escape uh=bla::blou()';

print_r($test->testArgs($args));

foreach (token_get_all('<?php '.$args.'?>') as $line)
{
    echo "\n";
    if (is_array($line))
    {
        echo token_name($line[0]) . ": ";
        echo $line[1];
        echo "\t";
    }
    echo str_replace("\n", "", print_r($line, true));
}

?>