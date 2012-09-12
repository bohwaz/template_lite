<?php

class Template_Syntax_Exception extends Exception
{
}

class Template_Parser
{
    const ATTR_STATE_NAME = 0;
    const ATTR_STATE_SEPARATOR = 1;
    const ATTR_STATE_VALUE = 2;

    const RESERVED_TPL_VAR_NAME = '(?:smarty|tpl|templatelite)';

    // Methods to extend and rewrite
    // These are actually just rewriting your template to the same code, for testing purpose
    public function processString($content)
    {
        if (is_array($content))
        {
            return '"'.implode('', $content).'"';
        }
        else
        {
            return '"'.$content.'"';
        }
    }

    public function processModifier($name, $content, $arguments, $map_array)
    {
        return "$content|$name";
    }

    public function processVariable($name)
    {
        return '$'.$name;
    }

    // These are parser methods, you should not extend or rewrite them

    public function __construct()
    {
        // matches double quoted strings:
        // "foobar"
        // "foo\"bar"
        // "foobar" . "foo\"bar"
        $this->_db_qstr_regexp = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';

        // matches single quoted strings:
        // 'foobar'
        // 'foo\'bar'
        $this->_si_qstr_regexp = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';

        // matches single or double quoted strings
        $this->_qstr_regexp = '(?:' . $this->_db_qstr_regexp . '|' . $this->_si_qstr_regexp . ')';

        // matches bracket portion of vars
        // [0]
        // [foo]
        // [$bar]
        // [#bar#]
        $this->_var_bracket_regexp = '\[[\$|\#]?\w+\#?\]';
        //		$this->_var_bracket_regexp = '\[\$?[\w\.]+\]';

        // matches section vars:
        // %foo.bar%
        $this->_svar_regexp = '\%\w+\.\w+\%';

        // matches $ vars (not objects):
        // $foo
        // $foo[0]
        // $foo[$bar]
        // $foo[5][blah]
        # $this->_dvar_regexp = '\$[a-zA-Z0-9_]{1,}(?:' . $this->_var_bracket_regexp . ')*(?:' . $this->_var_bracket_regexp . ')*';
        $this->_dvar_regexp = '\$[a-zA-Z0-9_]{1,}(?:' . $this->_var_bracket_regexp . ')*(?:\.\$?\w+(?:' . $this->_var_bracket_regexp . ')*)*';

        // matches config vars:
        // #foo#
        // #foobar123_foo#
        $this->_cvar_regexp = '\#[a-zA-Z0-9_]{1,}(?:' . $this->_var_bracket_regexp . ')*(?:' . $this->_var_bracket_regexp . ')*\#';

        // matches valid variable syntax:
        // $foo
        // 'text'
        // "text"
        $this->_var_regexp = '(?:(?:' . $this->_dvar_regexp . '|' . $this->_cvar_regexp . ')|' . $this->_qstr_regexp . ')';

        // matches valid modifier syntax:
        // |foo
        // |@foo
        // |foo:"bar"
        // |foo:$bar
        // |foo:"bar":$foobar
        // |foo|bar
        $this->_mod_regexp = '(?:\|@?[0-9a-zA-Z_]+(?::(?>-?\w+|' . $this->_dvar_regexp . '|' . $this->_qstr_regexp .'))*)';

        // matches valid function name:
        // foo123
        // _foo_bar
        $this->_func_regexp = '(?:[a-zA-Z_]+\:\:)?[a-zA-Z_]+';
        //		$this->_func_regexp = '[a-zA-Z_]\w*';
    }

    protected function parseArguments($args_str)
    {
        $result = array();
        $last_value = '';
        $state = self::ATTR_STATE_NAME;

        preg_match_all('/(?:' . $this->_qstr_regexp . ' | (?>[^"\'=\s]+))+|[=]/x', $args_str, $match);

        foreach ($match[0] as $value)
        {
            if ($state == self::ATTR_STATE_NAME)
            {
                if (!is_string($value))
                    throw new Template_Syntax_Exception("Invalid attribute name '".$value."'.");

                $attr_name = $value;
                $state = self::ATTR_STATE_SEPARATOR;
            }
            elseif ($state == self::ATTR_STATE_SEPARATOR)
            {
                if ($value != '=')
                    throw new Template_Syntax_Exception("Expecting '=' after '".$last_value."'");

                $state = self::ATTR_STATE_VALUE;
            }
            elseif ($state == self::ATTR_STATE_VALUE)
            {
                if ($value == '=')
                    throw new Template_Syntax_Exception("Unexpected '=' after '".$last_value."'");

                if ($value == 'yes' || $value == 'on' || $value == 'true')
                    $value = true;
                elseif ($value == 'no' || $value == 'off' || $value == 'false')
                    $value = false;
                elseif ($value == 'null')
                    $value = null;

                if (preg_match_all('/(?:(' . $this->_var_regexp . '|' . $this->_svar_regexp . ')(' . $this->_mod_regexp . '*))(?:\s+(.*))?/xs', $value, $variables))
                {
                    list($value) = $this->parseVariables($variables[1], $variables[2]);
                    $result[$attr_name] = $value;
                }
                else
                {
                    $result[$attr_name] = $value;
                }

                $state = self::ATTR_STATE_NAME;
            }

            $last_value = $value;
        }

        if ($state == self::ATTR_STATE_SEPARATOR)
            throw new Template_Syntax_Exception("Expecting '=' after '".$last_value."'");
        elseif ($state == self::ATTR_STATE_VALUE)
            throw new Template_Syntax_Exception("Missing attribute value after '".$last_value."'");

        return $result;
    }

    protected function parseVariables($variables, $modifiers)
    {
        $result = array();

        foreach($variables as $key => $value)
        {
            $tag_variable = trim($variables[$key]);
            /*
            if(!empty($this->default_modifiers) && !preg_match('!(^|\|)templatelite:nodefaults($|\|)!',$modifiers[$key]))
            {
                $_default_mod_string = implode('|',(array)$this->default_modifiers);
                $modifiers[$key] = empty($modifiers[$key]) ? $_default_mod_string : $_default_mod_string . '|' . $modifiers[$key];
            }*/

            if (empty($modifiers[$key]))
            {
                $result[] = $this->parseVariable($tag_variable);
            }
            else
            {
                $result[] = $this->parseModifier($this->parseVariable($tag_variable), $modifiers[$key]);
            }
        }

        return $result;
    }

    protected function parseVariable($variable)
    {
        // replace variable with value
        if ($variable[0] == '$')
        {
            // replace the variable
            #return $this->_compile_variable($variable);
            return $this->processVariable(substr($variable, 1));
        }
        elseif ($variable[0] == '#')
        {
            // replace the config variable
            #return $this->_compile_config($variable);
            return $this->processConfigVariable(substr($variable, 1));
        }
        elseif ($variable[0] == '"')
        {
            // Parse classic string
            $variable = substr($variable, 1, -1);
            $result = array();

            // replace all quoted variables by simple variables
            $variable = preg_replace('!`('.$this->_dvar_regexp.')`!', '\\1', $variable);

            // Split string between variables, if they are not escaped
            // (will parse "hi $name" but no "hi \$name"
            $parts = preg_split('!(^|[^\\])('.$this->_dvar_regexp.')!', $variable, -1,
                PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_NO_EMPTY);

            foreach ($parts as $key=>$part)
            {
                if ($part[0][0] == '$')
                    $result[] = $this->processVariable(substr($part[0], 1));
                else
                    $result[] = $part[0];
            }

            return $this->processString($result);

/*
            // expand the quotes to pull any variables out of it
            // fortunately variables inside of a quote aren't fancy, no modifiers, no quotes
            //   just get everything from the $ to the ending space and parse it
            // if the $ is escaped, then we won't expand it
            //preg_match_all('/(?:[^\\\]' . $this->_dvar_regexp . ')/', $variable, $expand);  // old match
            // preg_match_all('/(?:[^\\\]' . $this->_dvar_regexp . '[^\\\])/', $variable, $_expand);
            if (($pos = strpos($variable, '$')) !== false)
            {
                while ($pos !== false)
                {
                }
            }

            foreach($expand as $key => $value)
            {
                $expand[$key] = trim($value);
                if (($pos = strpos($expand[$key], '$')) > 0)
                {
                    $expand[$key] = substr($expand[$key], $pos);
                }
            }

            $result = $variable;
            foreach($expand as $value)
            {
                $value = trim($value);
                $result = str_replace($value, '" . ' . $this->parseVariable($value) . ' . "', $result);
            }
            $result = str_replace("`", "", $result);

            return $result;
        */
        }
        elseif ($variable[0] == "'")
        {
            // return the value just as it is
            return $this->processString(substr($variable, 1, -1));
        }
        elseif ($variable[0] == '%')
        {
            return $this->parseSection($variable);
        }
        else
        {
            // return it as is; i believe that there was a reason before that i did not just return it as is,
            // but i forgot what that reason is ...
            // the reason i return the variable 'as is' right now is so that unquoted literals are allowed
            return $this->processString($variable);
        }
    }

    protected function parseModifier($variable, $modifiers)
    {
        $mods = array(); // stores all modifiers
        $args = array(); // modifier arguments

        preg_match_all('!\|(@?\w+)((?>:(?:'. $this->_qstr_regexp . '|[^|]+))*)!', '|' . $modifiers, $match);
        list(, $mods, $args) = $match;

        $count_mods = count($mods);
        for ($i = 0, $for_max = $count_mods; $i < $for_max; $i++)
        {
            preg_match_all('!:(' . $this->_qstr_regexp . '|[^:]+)!', $args[$i], $match);
            $arg = $match[1];

            if ($mods[$i]{0} == '@')
            {
                $mods[$i] = substr($mods[$i], 1);
                $map_array = 0;
            }
            else
            {
                $map_array = 1;
            }

            foreach($arg as $key => $value)
            {
                $arg[$key] = $this->parseVariable($value);
            }

            //$variable = $this->callCompiler('modifier', array('name' => $mods[$i], 'content' => $variable, 'misc' => $map_array, 'args' => $arg));
            $variable = $this->processModifier($mods[$i], $variable, $arg, $map_array);
            /*
            if ($this->_plugin_exists($_mods[$i], "modifier") || function_exists($_mods[$i]))
            {
                if (count($_arg) > 0)
                {
                    $_arg = ', '.implode(', ', $_arg);
                }
                else
                {
                    $_arg = '';
                }

                $php_function = "PHP";
                if ($this->_plugin_exists($_mods[$i], "modifier"))
                {
                    $php_function = "plugin";
                }
                $variable = "\$this->_run_modifier($variable, '$_mods[$i]', '$php_function', $_map_array$_arg)";
            }
            else
            {
                $variable = "\$this->trigger_error(\"'" . $_mods[$i] . "' modifier does not exist\", E_USER_NOTICE, __FILE__, __LINE__);";
            }*/
        }

        return $variable;
    }

}

?>