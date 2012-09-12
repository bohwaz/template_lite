<?php

class Template_Syntax_Exception extends Exception
{
}

class Template_Parser
{
    const CONTEXT_ARGUMENT = 10;
    const CONTEXT_STRING = 11;

    public $left_delimiter = '{';
    public $right_delimiter = '}';

    protected $_allowed_in_variable = array(T_VARIABLE, T_OBJECT_OPERATOR, T_STRING,
        T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_DOUBLE_COLON, '(', ')');

    protected $_allowed_in_string = array(T_STRING,
        T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE);

    public $reserved_var_name = '(?:smarty|tpl|templatelite)';

    public function __construct()
    {
    }

    protected function processVariable($variable, $modifiers = array())
    {
        return '--var('.$variable.')';
    }

/*
    public function parse($content)
    {
        $in_literal = false;

        $pos = strpos($content, $this->left_delimiter);

        while ($pos !== false)
        {
            if (($end = strpos($content,
            if ($in_literal)
        }

        $tokens = token_get_all('<?php '.$content.' ?>');

        foreach ($tokens as $token)
        {
            if (is_array($token))
                list($token, $text, $line) = $token;

            switch ($token)
            {
                case T_OPEN_TAG:
                    break;
                default:
                    if ($token >= 300)
                        echo token_name($token) . ": ".$text;
                    else echo $token;
                    echo "\n";
                    break;
            }
        }
    }
*/

    public function parseArguments($string)
    {
        $args = array();
        $status = 0; // 0 = nothing, 1 = arg named, waiting for equal sign, 2 = waiting for content
        $arg_name = null;
        $arg_value = null;

        foreach (token_get_all('<?php '.$string.'?>') as $t)
        {
            switch ($t[0])
            {
                case T_OPEN_TAG:
                case T_CLOSE_TAG:
                    continue(2);
                case T_STRING:
                    if ($status == 0)
                    {
                        $arg_name = $t[1];
                        $arg_value = '';
                        $status++;
                    }
                    elseif ($status == 2)
                    {
                        $arg_value .= $t[1];
                    }
                    break;
                case "\"":
                case "\'":
                    if ($status != 2)
                    {
                        throw new Template_Syntax_Exception("Expecting '=' sign after argument name");
                    }
                    $arg_value .= $t;
                    break;
                case '=':
                    if ($status != 1)
                    {
                        throw new Template_Syntax_Exception("Unexpected '=' sign");
                    }
                    $status++;
                    break;
                case T_ENCAPSED_AND_WHITESPACE:
                case T_VARIABLE:
                case T_OBJECT_OPERATOR:
                case T_CONSTANT_ENCAPSED_STRING:
                case T_DOUBLE_COLON:
                    if ($status != 2)
                    {
                        throw new Template_Syntax_Exception("Expecting '=' sign after argument name");
                    }

                    if ($t[0] == T_CONSTANT_ENCAPSED_STRING)
                        $arg_value = substr($t[1], 1, -1);
                    else
                        $arg_value .= $t[1];

                    break;
                case T_WHITESPACE:
                    if ($status == 2)
                    {
                        $args[$arg_name] = $arg_value;
                        $arg_name = $arg_value = null;
                        $status = 0;
                    }
                    break;
                case !isset($t[1]):
                    if ($status != 2)
                    {
                        throw new Template_Syntax_Exception("Expecting '=' sign after argument name");
                    }

                    $arg_value .= $t;
                    break;
                default:
                    break;
            }
        }

        if ($arg_value != null && $arg_name != null && !array_key_exists($arg_name, $args))
        {
            $args[$arg_name] = $arg_value;
        }

        return $args;
    }

    public function parseArgumentContent($content)
    {
        $content = trim($content);

        if (empty($content))
            return $content;

        $quotes = $content[0] . substr($content, -1);

        if ($quotes == "\"\"" || $quotes == '\'\'')
        {
            $content = substr($content, 1, -1);
            $inline = ($quotes == "\'\'") ? false : true;
            $out = '';
            $current_var = false;

            foreach (token_get_all('<?php "'.$content.'" ?>') as $t)
            {
                if ($current_var === true)
                {
                    if ($t[0] == T_VARIABLE || ($t[0] == T_STRING && $t[1][0] == '$'))
                    {
                        $current_var = $t[1];
                    }
                    else
                    {
                        $out .= '`';
                        $current_var = false;
                    }
                }

                if ($current_var)
                {
                    list($variable, $modifiers) = $this->parseVariable($current_var);
                    $out .= $this->processVariable($variable, $modifiers, self::CONTEXT_STRING);
                    $current_var = false;
                }
                elseif ($inline && $t[0] == T_VARIABLE)
                {
                    $current_var = $t[1];
                }
                elseif ($t[0] == '`')
                {
                    $current_var = true;
                }

                if ($current_var === false && in_array($t[0], $this->_allowed_in_string))
                {
                    $out .= isset($t[1]) ? $t[1] : $t[0];
                }
                else
                {
                    echo (isset($t[1]) ? token_name($t[0]) . ' ' . htmlspecialchars($t[1]) : htmlspecialchars($t[0])). "<br />";
                }
            }

            return $out;
        }
        else
        {
            list($variable, $modifiers) = $this->parseVariable($content);
            return $this->processVariable($variable, $modifiers, self::CONTEXT_ARGUMENT);
        }
    }

    public function parseVariable($string)
    {
        $variable = '';
        $modifiers = array();
        $current_modifier = false;
        $current_arg = false;

        foreach (token_get_all('<?php '.$string.'?>') as $t)
        {
            if ($t[0] == T_STRING)
            {
                if ($current_modifier === true)
                {
                    $modifiers[] = array($t[1]);
                    $current_modifier = count($modifiers) - 1;
                    $current_arg = 1;
                    continue;
                }
            }

            $content = isset($t[1]) ? $t[1] : $t[0];

            if ($t[0] == T_CONSTANT_ENCAPSED_STRING)
                $content = substr($content, 1, -1);

            if ($t[0] == '|')
                $current_modifier = true;
            elseif ($t[0] == ':')
                $current_arg++;
            elseif ($current_modifier === false && in_array($t[0], $this->_allowed_in_variable))
            {
                $variable .= $content;
            }
            elseif (is_int($current_modifier) && in_array($t[0], $this->_allowed_in_variable))
            {
                if (!array_key_exists($current_arg, $modifiers[$current_modifier]))
                {
                    $modifiers[$current_modifier][$current_arg] = '';
                }

                $modifiers[$current_modifier][$current_arg] .= $content;
            }
            else
            {
            }
        }

        return array($variable, $modifiers);
    }

    public function parseTokens($string)
    {
        echo '<table>';
        foreach (token_get_all('<?php '.$string.'?>') as $t)
        {
            echo "<tr>";
            if (is_array($t))
            {
                echo '<th>'.token_name($t[0]).'</th>';
                echo '<td>'.htmlspecialchars($t[1]).'</td>';
                echo '<td>'.($t[2]).'</td>';
            }
            else
            {
                echo '<th>--</th>';
                echo '<td>'.htmlspecialchars($t).'</td>';
                echo '<td></td>';
            }
            echo '</tr>';
        }
    }
}

?>
