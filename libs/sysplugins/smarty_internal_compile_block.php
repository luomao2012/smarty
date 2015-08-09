<?php

/**
 * Smarty Internal Plugin Compile Block
 * Compiles the {block}{/block} tags
 *
 * @package    Smarty
 * @subpackage Compiler
 * @author     Uwe Tews
 */

/**
 * Smarty Internal Plugin Compile Block Class
 *
 * @package    Smarty
 * @subpackage Compiler
 */
class Smarty_Internal_Compile_Block extends Smarty_Internal_CompileBase
{

    const parent = '____SMARTY_BLOCK_PARENT____';

    /**
     * Attribute definition: Overwrites base class.
     *
     * @var array
     * @see Smarty_Internal_CompileBase
     */
    public $required_attributes = array('name');

    /**
     * Attribute definition: Overwrites base class.
     *
     * @var array
     * @see Smarty_Internal_CompileBase
     */
    public $shorttag_order = array('name');

    /**
     * Attribute definition: Overwrites base class.
     *
     * @var array
     * @see Smarty_Internal_CompileBase
     */
    public $option_flags = array('hide', 'append', 'prepend', 'nocache');

    /**
     * nested child block names
     *
     * @var array
     */
    public static $nested_block_names = array();

    /**
     * child block source buffer
     *
     * @var array
     */
    public static $block_data = array();

    /**
     * Compiles code for the {block} tag
     *
     * @param array                                 $args     array with attributes from parser
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     *
     * @return bool true
     */
    public function compile($args, Smarty_Internal_TemplateCompilerBase $compiler)
    {
        // check and get attributes
        $_attr = $this->getAttributes($compiler, $args);
        $_name = trim($_attr['name'], "\"'");

        // existing child must override parent settings
        if (isset($compiler->template->block_data[$_name]) &&
            $compiler->template->block_data[$_name]['mode'] == 'replace'
        ) {
            $_attr['append'] = false;
            $_attr['prepend'] = false;
        }

        // check if we process an inheritance child template
        if ($compiler->inheritance_child) {
            array_unshift(self::$nested_block_names, $_name);
            // build {block} for child block
            self::$block_data[$_name]['source'] = "{$compiler->smarty->left_delimiter}private_child_block name={$_attr['name']} uid='{$compiler->template->source->uid}' line={$compiler->parser->lex->line}";
            if ($_attr['nocache']) {
                self::$block_data[$_name]['source'] .= ' nocache';
            }
            self::$block_data[$_name]['source'] .= $compiler->smarty->right_delimiter;
            // save source object
            $compiler->cache["source_{$compiler->template->source->uid}"] = $compiler->template->source;

            $save = array($_attr, $compiler->inheritance);
            $this->openTag($compiler, 'block', $save);
            // set flag for {block} tag
            $compiler->inheritance = true;
            $compiler->parser->lex->yypushstate(Smarty_Internal_Templatelexer::CHILDBLOCK);
            $compiler->has_code = false;
            return null;
        }
        // must merge includes
        if ($_attr['nocache'] == true) {
            $compiler->tag_nocache = true;
        }
        $save = array($_attr, $compiler->inheritance, $compiler->parser->current_buffer, $compiler->nocache);
        $this->openTag($compiler, 'block', $save);
        $compiler->inheritance = true;
        $compiler->nocache = $compiler->nocache | $compiler->tag_nocache;

        $compiler->parser->current_buffer = new Smarty_Internal_ParseTree_Template();
        $compiler->has_code = false;

        return true;
    }

    /**
     * Compile saved child block source
     *
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     * @param string                                $_name    optional name of child block
     *
     * @return string compiled code of child block
     * @throws \SmartyCompilerException
     */
    static function compileChildBlock(Smarty_Internal_TemplateCompilerBase $compiler, $_name = null)
    {
        if ($compiler->inheritance_child) {
            $name1 = Smarty_Internal_Compile_Block::$nested_block_names[0];
            if (isset($compiler->template->block_data[$name1])) {
                //  replace inner block name with generic
                Smarty_Internal_Compile_Block::$block_data[$name1]['source'] .= $compiler->template->block_data[$name1]['source'];
                Smarty_Internal_Compile_Block::$block_data[$name1]['child'] = true;
            }
            $compiler->parser->lex->yypushstate(Smarty_Internal_Templatelexer::CHILDBLOCK);
            $compiler->has_code = false;
            return null;
        }
        // if called by {$smarty.block.child} we must search the name of enclosing {block}
        if ($_name == null) {
            $stack_count = count($compiler->_tag_stack);
            while (-- $stack_count >= 0) {
                if ($compiler->_tag_stack[$stack_count][0] == 'block') {
                    $_name = trim($compiler->_tag_stack[$stack_count][1][0]['name'], "\"'");
                    break;
                }
            }
        }
        if ($_name == null) {
            $compiler->trigger_template_error(' tag {$smarty.block.child} used outside {block} tags ', null, true);
        }
        // undefined child?
        if (!isset($compiler->template->block_data[$_name]['source'])) {
            $compiler->popTrace();
            return '';
        }
        // flag that child is already compile by {$smarty.block.child} inclusion
        $compiler->template->block_data[$_name]['compiled'] = true;
        $_tpl = new Smarty_Internal_template('string:' .
                                             $compiler->template->block_data[$_name]['source'], $compiler->smarty, $compiler->template, $compiler->template->cache_id, $compiler->template->compile_id, $compiler->template->caching, $compiler->template->cache_lifetime);
        if ($compiler->smarty->debugging) {
            Smarty_Internal_Debug::ignore($_tpl);
        }
        $_tpl->tpl_vars = $compiler->template->tpl_vars;
        $_tpl->variable_filters = $compiler->template->variable_filters;
        $_tpl->compiled->nocache_hash = $compiler->template->compiled->nocache_hash;
        $_tpl->allow_relative_path = true;
        $_tpl->loadCompiler();
        $_tpl->compiler->parent_compiler = $compiler;
        $_tpl->compiler->_tag_objects = $compiler->_tag_objects;
        $_tpl->compiler->inheritance = true;
        $_tpl->compiler->suppressHeader = true;
        $_tpl->compiler->suppressFilter = true;
        $_tpl->compiler->suppressTemplatePropertyHeader = true;
        $nocache = $compiler->nocache || $compiler->tag_nocache;
        $_output = '';
        if (strpos($compiler->template->block_data[$_name]['source'], self::parent) !== false) {
            $_output = str_replace(self::parent, $compiler->parser->current_buffer->to_smarty_php($compiler->parser), $_tpl->compiler->compileTemplate($_tpl, $nocache, $compiler->parent_compiler));
        } elseif ($compiler->template->block_data[$_name]['mode'] == 'prepend') {
            $_output = $_tpl->compiler->compileTemplate($_tpl, $nocache, $compiler->parent_compiler) .
                $compiler->parser->current_buffer->to_smarty_php($compiler->parser);
        } elseif ($compiler->template->block_data[$_name]['mode'] == 'append') {
            $_output = $compiler->parser->current_buffer->to_smarty_php($compiler->parser) .
                $_tpl->compiler->compileTemplate($_tpl, $nocache, $compiler->parent_compiler);
        } elseif (!empty($compiler->template->block_data[$_name])) {
            $_output = $_tpl->compiler->compileTemplate($_tpl, $nocache, $compiler->parent_compiler);
        }
        $compiler->template->compiled->file_dependency = array_merge($compiler->template->compiled->file_dependency, $_tpl->compiled->file_dependency);
        unset($compiler->template->compiled->file_dependency[$_tpl->source->uid]);
        $compiler->template->tpl_function = array_merge($compiler->template->tpl_function, $_tpl->tpl_function);
        $compiler->template->variable_filters = $_tpl->variable_filters;
        if ($_tpl->compiled->has_nocache_code) {
            $compiler->template->compiled->has_nocache_code = true;
        }
        foreach ($_tpl->compiled->required_plugins as $key => $tmp1) {
            if ($compiler->nocache && $compiler->template->caching) {
                $code = 'nocache';
            } else {
                $code = $key;
            }
            foreach ($tmp1 as $name => $tmp) {
                foreach ($tmp as $type => $data) {
                    $compiler->parent_compiler->template->compiled->required_plugins[$code][$name][$type] = $data;
                }
            }
        }
        $compiler->_tag_objects = $_tpl->compiler->_tag_objects;
        unset($_tpl);
        $compiler->has_code = true;
        return $_output;
    }

    /**
     * Compile $smarty.block.parent
     *
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     * @param string                                $_name    optional name of child block
     *
     * @return string compiled code of child block
     * @throws \SmartyCompilerException
     */
    static function compileParentBlock(Smarty_Internal_TemplateCompilerBase $compiler, $_name = null)
    {
        // if called by {$smarty.block.parent} we must search the name of enclosing {block}
        if ($_name == null) {
            $stack_count = count($compiler->_tag_stack);
            while (-- $stack_count >= 0) {
                if ($compiler->_tag_stack[$stack_count][0] == 'block') {
                    $_name = trim($compiler->_tag_stack[$stack_count][1][0]['name'], "\"'");
                    break;
                }
            }
        }
        if ($_name == null) {
            $compiler->trigger_template_error(' tag {$smarty.block.parent} used outside {block} tags ', null, true);
        }
        if (empty(Smarty_Internal_Compile_Block::$nested_block_names)) {
            $compiler->trigger_template_error(' illegal {$smarty.block.parent} in parent template ', null, true);
        }
        Smarty_Internal_Compile_Block::$block_data[Smarty_Internal_Compile_Block::$nested_block_names[0]]['source'] .= Smarty_Internal_Compile_Block::parent;
        $compiler->parser->lex->yypushstate(Smarty_Internal_Templatelexer::CHILDBLOCK);
        $compiler->has_code = false;
        return;
    }

    /**
     * Process block source
     *
     * @param \Smarty_Internal_TemplateCompilerBase $compiler
     * @param string                                $source source text
     */
    static function blockSource(Smarty_Internal_TemplateCompilerBase $compiler, $source)
    {
        Smarty_Internal_Compile_Block::$block_data[Smarty_Internal_Compile_Block::$nested_block_names[0]]['source'] .= $source;
    }
}

/**
 * Smarty Internal Plugin Compile BlockClose Class
 *
 * @package    Smarty
 * @subpackage Compiler
 */
class Smarty_Internal_Compile_Blockclose extends Smarty_Internal_CompileBase
{
    /**
     * Compiles code for the {/block} tag
     *
     * @param array                                 $args     array with attributes from parser
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     *
     * @return string compiled code
     */
    public function compile($args, Smarty_Internal_TemplateCompilerBase $compiler)
    {
        $compiler->has_code = true;
        $saved_data = $this->closeTag($compiler, array('block'));
        $_name = trim($saved_data[0]['name'], "\"'");
        // reset flag for {block} tag
        $compiler->inheritance = $saved_data[1];
        // check if we process an inheritance child template
        if ($compiler->inheritance_child) {
            $name1 = Smarty_Internal_Compile_Block::$nested_block_names[0];
            Smarty_Internal_Compile_Block::$block_data[$name1]['source'] .= "{$compiler->smarty->left_delimiter}/private_child_block{$compiler->smarty->right_delimiter}";
            array_shift(Smarty_Internal_Compile_Block::$nested_block_names);
            if (!empty(Smarty_Internal_Compile_Block::$nested_block_names)) {
                $name2 = Smarty_Internal_Compile_Block::$nested_block_names[0];
                if (isset($compiler->template->block_data[$name1]) || !$saved_data[0]['hide']) {
                    if (isset(Smarty_Internal_Compile_Block::$block_data[$name1]['child']) ||
                        !isset($compiler->template->block_data[$name1])
                    ) {
                        Smarty_Internal_Compile_Block::$block_data[$name2]['source'] .= Smarty_Internal_Compile_Block::$block_data[$name1]['source'];
                    } else {
                        if ($compiler->template->block_data[$name1]['mode'] == 'append') {
                            Smarty_Internal_Compile_Block::$block_data[$name2]['source'] .= Smarty_Internal_Compile_Block::$block_data[$name1]['source'] .
                                $compiler->template->block_data[$name1]['source'];
                        } elseif ($compiler->template->block_data[$name1]['mode'] == 'prepend') {
                            Smarty_Internal_Compile_Block::$block_data[$name2]['source'] .= $compiler->template->block_data[$name1]['source'] .
                                Smarty_Internal_Compile_Block::$block_data[$name1]['source'];
                        } else {
                            Smarty_Internal_Compile_Block::$block_data[$name2]['source'] .= $compiler->template->block_data[$name1]['source'];
                        }
                    }
                }
                unset(Smarty_Internal_Compile_Block::$block_data[$name1]);
                $compiler->parser->lex->yypushstate(Smarty_Internal_Templatelexer::CHILDBLOCK);
            } else {
                if (isset($compiler->template->block_data[$name1]) || !$saved_data[0]['hide']) {
                    if (isset($compiler->template->block_data[$name1]) &&
                        !isset(Smarty_Internal_Compile_Block::$block_data[$name1]['child'])
                    ) {
                        if (strpos($compiler->template->block_data[$name1]['source'], Smarty_Internal_Compile_Block::parent) !==
                            false
                        ) {
                            $compiler->template->block_data[$name1]['source'] = str_replace(Smarty_Internal_Compile_Block::parent, Smarty_Internal_Compile_Block::$block_data[$name1]['source'], $compiler->template->block_data[$name1]['source']);
                        } elseif ($compiler->template->block_data[$name1]['mode'] == 'prepend') {
                            $compiler->template->block_data[$name1]['source'] .= Smarty_Internal_Compile_Block::$block_data[$name1]['source'];
                        } elseif ($compiler->template->block_data[$name1]['mode'] == 'append') {
                            $compiler->template->block_data[$name1]['source'] = Smarty_Internal_Compile_Block::$block_data[$name1]['source'] .
                                $compiler->template->block_data[$name1]['source'];
                        }
                    } else {
                        $compiler->template->block_data[$name1]['source'] = Smarty_Internal_Compile_Block::$block_data[$name1]['source'];
                    }
                    $compiler->template->block_data[$name1]['mode'] = 'replace';
                    if ($saved_data[0]['append']) {
                        $compiler->template->block_data[$name1]['mode'] = 'append';
                    }
                    if ($saved_data[0]['prepend']) {
                        $compiler->template->block_data[$name1]['mode'] = 'prepend';
                    }
                }
                unset(Smarty_Internal_Compile_Block::$block_data[$name1]);
                $compiler->parser->lex->yypushstate(Smarty_Internal_Templatelexer::CHILDBODY);
            }
            $compiler->has_code = false;
            return null;
        }
        if (isset($compiler->template->block_data[$_name]) &&
            !isset($compiler->template->block_data[$_name]['compiled'])
        ) {
            $_output = Smarty_Internal_Compile_Block::compileChildBlock($compiler, $_name);
        } else {
            if ($saved_data[0]['hide'] && !isset($compiler->template->block_data[$_name]['source'])) {
                $_output = '';
            } else {
                $_output = $compiler->parser->current_buffer->to_smarty_php($compiler->parser);
            }
        }
        if (isset($compiler->template->block_data[$_name]['compiled'])) {
            unset($compiler->template->block_data[$_name]['compiled']);
        }
        // reset flags
        $compiler->parser->current_buffer = $saved_data[2];
        if ($compiler->nocache) {
            $compiler->tag_nocache = true;
        }
        $compiler->nocache = $saved_data[3];
        // $_output content has already nocache code processed
        $compiler->suppressNocacheProcessing = true;

        return $_output;
    }
}

/**
 * Smarty Internal Plugin Compile Child Block Class
 *
 * @package    Smarty
 * @subpackage Compiler
 */
class Smarty_Internal_Compile_Private_Child_Block extends Smarty_Internal_CompileBase
{

    /**
     * Attribute definition: Overwrites base class.
     *
     * @var array
     * @see Smarty_Internal_CompileBase
     */
    public $required_attributes = array('name', 'uid', 'line');

    /**
     * Compiles code for the {private_child_block} tag
     *
     * @param array                                 $args     array with attributes from parser
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     *
     * @return bool true
     */
    public function compile($args, Smarty_Internal_TemplateCompilerBase $compiler)
    {
        // check and get attributes
        $_attr = $this->getAttributes($compiler, $args);
        $uid = trim($_attr['uid'], "\"'");
        // update template with original template source of {block}
        $compiler->template->source = $compiler->parent_compiler->cache["source_{$uid}"];

        // must merge includes
        if ($_attr['nocache'] == true) {
            $compiler->tag_nocache = true;
        }
        $save = array($_attr, $compiler->nocache);

        // set trace back to child block
        $compiler->pushTrace($compiler->template->source->filepath, $uid, $_attr['line'] -
                                                                  $compiler->parser->lex->line);

        $this->openTag($compiler, 'private_child_block', $save);

        $compiler->nocache = $compiler->nocache | $compiler->tag_nocache;
        $compiler->has_code = false;

        return true;
    }
}

/**
 * Smarty Internal Plugin Compile Child Block Close Class
 *
 * @package    Smarty
 * @subpackage Compiler
 */
class Smarty_Internal_Compile_Private_Child_Blockclose extends Smarty_Internal_CompileBase
{

    /**
     * Compiles code for the {/private_child_block} tag
     *
     * @param array                                 $args     array with attributes from parser
     * @param \Smarty_Internal_TemplateCompilerBase $compiler compiler object
     *
     * @return bool true
     */
    public function compile($args, Smarty_Internal_TemplateCompilerBase $compiler)
    {
         $saved_data = $this->closeTag($compiler, array('private_child_block'));

        // end of child block
        $compiler->popTrace();

        $compiler->nocache = $saved_data[1];
        $compiler->has_code = false;

        return true;
    }
}
