<?php

namespace Pivel\Hydro2\Views;

use Pivel\Hydro2\Hydro2;
use ReflectionClass;
use ReflectionException;

class BaseView
{
    protected ReflectionClass $rc;
    protected array $properties;
    protected array $viewClassesUsed = [];

    public function Render(Hydro2 $app, bool $isOuter=true) : string {
        // load template from View attribute? or just look for template with matching class name?
        $this->rc = new ReflectionClass($this);
        $this->properties = array_map(fn($a) => $a->name,$this->rc->getProperties());
        $this->viewClassesUsed = [];
        // add self and parent classes
        $c = get_called_class();
        while ($c !== false) {
            $this->viewClassesUsed[] = $c;
            $c = get_parent_class($c);
        }
        // reverse because parent scripts must be included first in basewebview.
        $this->viewClassesUsed = array_reverse($this->viewClassesUsed);
        $templatePath = str_replace('.php','.template.html', $this->rc->getFileName());
        $template = file_get_contents($templatePath);
        $rendered = $this->ResolveTemplate($app, $template);
        $renderedContent = $rendered['content'];
        return $renderedContent;
    }

    public function GetViewClassesUsed() : array {
        return array_unique($this->viewClassesUsed);
    }

    protected function ResolveTemplate(Hydro2 $app, string $template) : array {
        // template placeholder format
        // {{name:space:class|arg1,"arg2",$vararg3}}
        // {{arg1}}
        
        // {#[tag] [args][#}|}...{#}]

        // parse through template
        //  if we've built a valid opening tag:
        //   start recording this tag
        //  once we reach the matching closing tag:
        //   evaluate the tag content (return object like [content=>"", args=>""]):
        //    [recursive] need to evaluate all sub-tags first
        //    {#arg ...} tags stored in tagArgBuffer[]
        //   evaluate the tag, passing the content/args.
        //   clear the tag recording buffer and tagArgBuffer[]
        // if we finish parsing the template but haven't found the current matching closing tag:
        //  there is an error with this template. Replace the current tag recording buffer with some
        //  text indicating the error.
        $debug = false;
        //$debug = true;

        $contentArgs = [];

        $tagLevel = 0;
        $tagSymbols = [];
        $tagType = '';
        $tagArgs = '';
        $tagStartingPos = -1;
        $tagContentStartingPos = -1;
        $tagContent = '';
        $tagContentArgs = [];
        $lastIfEvaluation = false;
        $cursor = 0;
        while ($cursor <= (strlen($template) - 1)) {
            // find next tag
            $matches = [];
            if (!preg_match("/{(?<symbol>\\$|#)(?<type>[A-Za-z\[\]]+)?(?: (?<args>(?:\"(?:\\\\\"|[^\"])*\"|'(?:\\\\'|[^'])*'|[A-Za-z\d\s\\\\(),:$\\[\\]._)])+))?(?<endsymbol>\\1)?}/",$template,$matches,PREG_OFFSET_CAPTURE,$cursor)) {
                // no remaining tags.
                $cursor = (strlen($template) - 1);
                break;
            }

            //$matches[0];
            //$matches['symbol']; // $ or #.
            //$matches['type']; // if not present, this is a closing tag. if symbol is $, this is the section name
            //$matches['args']; // if not present, there are no additional arguments to this tag.
            //$matches['endsymbol']; // if present, this tag is also closed.

            if ($debug) {
                print_r($matches[0][0]);
                echo "<br />\n";
            }
            
            $cursor = $matches[0][1] + strlen($matches[0][0]);
            if (isset($matches['type'])) {
                // is an opening tag
                $tagLevel++;
                $tagSymbols[$tagLevel] = $matches['symbol'][0];

                if ($tagLevel === 1) {
                    $tagType = $matches['type'][0];
                    $tagArgs = isset($matches['args']) ? $matches['args'][0] : '';
                    $tagStartingPos = $matches[0][1];
                    $tagContentStartingPos = $cursor;
                }

                if (isset($matches['endsymbol'])) {
                    // this is a self-closing tag.
                    $tagLevel--;
                }
            } else if ($tagLevel >= 0 && $matches['symbol'][0] == $tagSymbols[$tagLevel]) {
                // is a closing tag (only if this level's opening symbol matches). Ignore when there are too many closing tags.
                $tagLevel = max($tagLevel-1, 0);
            }

            if ($debug) {
                echo "tag level: {$tagLevel}<br />\n";
            }

            if ($tagLevel != 0) {
                continue;
            }

            // we've finished parsing a tag at the current level. evaluate it.
            // tag content is from startingpos to the character before the current tag.
            $tagContentLength = $matches[0][1]-$tagContentStartingPos;
            $tagContent = '';
            if ($tagContentLength > 0) {
                $tagContent = substr($template, $tagContentStartingPos, $tagContentLength);
                // trim whitespace
                $tagContent = trim($tagContent);
            }

            // what kind of tag is this?
            if ($tagSymbols[1] == '$') {
                // {$[section name]$}
                // is a synonym of {#section [section name]#}
                $tagArgs = $tagType;
                $tagType = 'section';
            }
            
            if ($debug) {
                echo "evaluating tag:<br />\n";
                echo "type: {$tagType}<br />\n";
                echo "args: {$tagArgs}<br />\n";
            }

            // valid tag types:
            //  section
            //  view
            //  arg
            //  parent
            //  if/else
            //  {$[section name]$}
            //  {$[section name]} ... {$}
            //  {$[section name][]} ... {$}
            //   name a section which will be filled in by the property matching [section name].
            //   if using sectionName[], the property must be an array.
            //   if the property is an object which extends BaseView, render it first
            //   if using the extended format, the text between the opening/closing tags are used as
            //   the property's default value (remove all leading/trailing whitespace).
            //   Example:
            //     "{#section Color#}"
            //     -> will be replaced with the value of the code-behind class' $Color property
            //
            //     "{#section Color}#FF0000{#}"
            //     -> if the class' $Color property is null or not defined, will be replaced with
            //        the default value "#FF0000"
            // 
            //  {#view name\space\class#}
            //  {#view name\space\class(arg_0,...,arg_n)} ... {#}
            //  {#view name\space\class(arg)}
            //      ...
            //      {#arg [arg name]} ... {#}
            //      {#arg [arg name]} ... {#}
            //      {#arg [arg name][]} ... {#}
            //  {#}
            //   Include another view here. Args may be a number, "string", or $property that will be replaced.
            //   Args may be in the format arg_name:arg_value to use a named arg.
            //   by the matching property in the code-behind class.
            //   Child {#arg [name]} ... {#} tags will also be passed to the view's constructor.
            //   since Hydro2 is targetting PHP >=8.1.0, an array of named args can be unpacked with: ...['b' => 2, 'a' => 1]
            //   Child {#arg [name][]} ... {#} tags' values will be added to an array which will be passed to the view's constructor.
            //
            //  {#parent} ... {#}
            //   Works the same way as {#view} but with this view's parent class.
            //
            //  {#if $arg} ... {#}
            //   If there is a matching $arg property and it is truthy, evaluate and replace with the contents
            //  {#else} ... {#}
            //   If there a previous #if evaluation and it evaluated to false, evaluate and replace with the contents and clear the previous #3
            //    evaluation. If there is not a previous #if evaluation, evaluate and replace with the contents anyways.
            //   

            switch ($tagType) {
                case 'arg':
                    // $tagArgs will be [arg name] or [arg name][]
                    $argName = rtrim($tagArgs, '[]');
                    $isArray = strlen($argName) < strlen($tagArgs); // did we have to trim []?

                    // resolve any child tags in the current content before we process.
                    if ($debug) {echo "resolving tag content<br />\n<blockquote>";}
                    $r = $this->ResolveTemplate($app, $tagContent);
                    if ($debug) {echo "</blockquote>done<br />\n";}
                    $tagContent = $r['content'];
                    $tagContentArgs = $r['args'];

                    if ($isArray) {
                        $contentArgs[$argName] ??= [];
                        $contentArgs[$argName][] = $tagContent;
                    } else {
                        $contentArgs[$argName] = $tagContent;
                    }
                    if ($debug) {echo "set arg '{$argName}' => {$tagContent}<br />\n";}
                    break;

                case 'section':
                    // $tagArgs will be [section name] or [section name][]
                    $sectionName = rtrim($tagArgs, '[]');
                    $isArray = strlen($sectionName) < strlen($tagArgs); // did we have to trim []?
                    
                    $sectionValue = '';
                    if (in_array($sectionName, $this->properties) && $this->$sectionName !== null) {
                        $sectionValue = $this->$sectionName;
                        if ($isArray && is_array($sectionValue)) {
                            $r = '';
                            foreach ($sectionValue as $v) {
                                if ($v instanceof BaseView) {
                                    $r .= $v->Render($app, isOuter:false);
                                    $this->viewClassesUsed = array_merge($this->viewClassesUsed, $v->GetViewClassesUsed());
                                } else {
                                    $r .= $v;
                                }
                            }
                            $sectionValue = $r;
                        } else {
                            // if property type extends BaseView, render first then replace
                            // TODO: need to prevent circular inclusions
                            if ($sectionValue instanceof BaseView) {
                                $sectionValue = $this->$sectionName->Render($app);
                                $this->viewClassesUsed = array_merge($this->viewClassesUsed, $this->$sectionName->GetViewClassesUsed());
                            }
                        }
                    } else {
                        // resolve any child tags in the current content before we process.
                        if ($debug) {echo "resolving tag content<br />\n<blockquote>";}
                        $r = $this->ResolveTemplate($app,$tagContent);
                        if ($debug) {echo "</blockquote>done<br />\n";}
                        $tagContent = $r['content'];
                        $tagContentArgs = $r['args'];

                        $sectionValue = $tagContent;
                    }
                    if ($debug) {echo "value: {$sectionValue}\n<br />";}

                    // replace from $tagStartingPos to $cursor with $sectionValue
                    // set $cursor to $tagStartingPos+strlen($sectionValue)
                    // TODO check for off-by-one issues
                    if ($debug) {
                        echo "replacing " . substr($template, $tagStartingPos, ($cursor-$tagStartingPos)) . "<br />\n";
                        echo "move cursor to: " . $tagStartingPos + strlen($sectionValue) . "<br />\n";
                    }
                    $template = substr_replace($template, $sectionValue, $tagStartingPos, ($cursor-$tagStartingPos));
                    $cursor = $tagStartingPos + strlen($sectionValue);
                    break;

                case 'parent':
                    // synonym for {#view parent\class\name}
                    // identify the called class' parent class, and set $tagArgs to the parent class' full name
                    $cc = get_called_class();
                    if ($debug) {echo "called class: {$cc}<br />\n";}
                    $pc = get_parent_class($cc);
                    if ($debug) {echo "called class's parent class: {$pc}<br />\n";}
                    $tagArgs = $pc;
                    // continue with 'view' case
                case 'view':
                    // $tagArgs will be name\space\class or name\space\class(arg_0,...,arg_n)
                    // need to combine $tagContentArgs and viewArgs, then pass to constructor.
                    $result = '';
                    $viewString = trim($tagArgs, '()');
                    $viewParts = explode('(', $viewString, 2);
                    $viewClassString = $viewParts[0];
                    if ($debug) {echo "view class: {$viewClassString}\n<br />";}
                    try {
                        $viewClass = new ReflectionClass($viewClassString);
                    } catch (ReflectionException) {
                        // class doesn't exist
                        $result = '';
                        $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                        $cursor = $tagStartingPos + strlen($result);
                        break;
                    }
                    $viewArgsString = $viewParts[1] ?? '';
                    if ($debug) {echo "view args string: {$viewArgsString}<br />\n";}
                    // split by ',' that are not inside a pair of "" ==> args[]
                    $viewArgs = empty($viewArgsString) ? [] : str_getcsv($viewArgsString);
                    // for each oldKey
                    $named = false;
                    foreach (array_keys($viewArgs) as $oldKey) {
                        $argString = trim($viewArgs[$oldKey]);
                        unset($viewArgs[$oldKey]);
                        $newKey = $oldKey;
                        $argNameMatches = [];
                        //  if starts with [[arg_name]:], this is a named arg. newKey = arg_name
                        //  else: if we've already had a named arg, further positional args are not allowed.
                        if (preg_match("/^(?<name>[0-9a-zA-Z_]+):/",$argString,$argNameMatches)) {
                            $named = true;
                            $newKey = $argNameMatches['name'];
                            $argString = trim(substr($argString,strlen($newKey)+1));
                        } else if ($named) {
                            // error, can't have a positional arg after a named arg.
                            continue;
                        }
                        //  if starts with [$], set args[newKey] to property if one matches (error if not matching)
                        if (str_starts_with($argString, '$')) {
                            $name = substr($argString, 1);
                            $value = '';
                            if (in_array($name, $this->properties) && $this->$name !== null) {
                                $value = $this->$name;
                                // if property type extends BaseView, render first then replace
                                // TODO: need to prevent circular inclusions
                                if ($this->$name instanceof BaseView) {
                                    $value = $this->$name->Render(isOuter:false);
                                    $this->viewClassesUsed = array_merge($this->viewClassesUsed, $this->$name->GetViewClassesUsed());
                                }
                            }
                            $viewArgs[$newKey] = $value;
                            continue;
                        }

                        //  else
                        //   if $named and starts or ends with ' or ", remove it (only 1x, so can't use trim) (if not named, " already removed
                        //    by str_getcsv).
                        if ($named) {
                            if (str_starts_with($argString, '"') || str_starts_with($argString, "'")) {
                                $argString = substr($argString, 1);
                            }
                            if (str_ends_with($argString, '"') || str_ends_with($argString, "'")) {
                                $argString = substr($argString, 0, strlen($argString)-1);
                            }
                        }
                        // TODO try to cast to float > int > bool > string
                        $viewArgs[$newKey] = $argString;
                    }

                    // resolve any child tags in the current content before we process.
                    if ($debug) {echo "resolving tag content<br />\n<blockquote>";}
                    $r = $this->ResolveTemplate($app, $tagContent);
                    if ($debug) {echo "</blockquote>done<br />\n";}
                    $tagContent = $r['content'];
                    $tagContentArgs = $r['args'];

                    $viewArgs = array_merge($viewArgs, $tagContentArgs);
                    if ($debug) {
                        echo "view args:";
                        print_r($viewArgs);
                        echo "<br />\n";
                    }
                    // -> instantiate the View object, render, and return result.
                    // TODO prevent circular inclusion
                    try {
                        $instance = $app->ResolveDependency($viewClass->name, $viewArgs);
                    } catch (ReflectionException) {
                        // there are more that 0 args and the class does not have a public constructor
                        $result = '';
                        $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                        $cursor = $tagStartingPos + strlen($result);
                        break;
                    }

                    if (!($instance instanceof BaseView)) {
                        // not an instance of BaseView
                        $result = '';
                        $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                        $cursor = $tagStartingPos + strlen($result);
                        break;
                    }

                    $result = $instance->Render($app, isOuter:false);
                    $this->viewClassesUsed = array_merge($this->viewClassesUsed, $instance->GetViewClassesUsed());
                    $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                    $cursor = $tagStartingPos + strlen($result);
                    break;

                case 'if':
                    $argName = rtrim($tagArgs, '[]');

                    if ($debug) {echo "if {$argName}:<br />\n";}

                    if (str_starts_with($argName, '$')) {
                        $argName = substr($argName, 1);
                    }
                    
                    $lastIfEvaluation = false;
                    if (in_array($argName, $this->properties) && $this->$argName !== null) {
                        $lastIfEvaluation = (bool)$this->$argName;
                        if ($debug) {echo "if evaluated to: ".($lastIfEvaluation?'true':'false')."<br />\n";}
                    } else {
                        if ($debug) {
                            echo "{$argName} not in object.<br />\n";
                            var_dump($this->properties);
                        }
                    }
                    
                    $result = '';
                    if ($lastIfEvaluation) {
                        // resolve any child tags in the current content before we process.
                        if ($debug) {echo "resolving tag content<br />\n<blockquote>";}
                        $r = $this->ResolveTemplate($app, $tagContent);
                        if ($debug) {echo "</blockquote>done<br />\n";}
                        $result = $r['content'];
                        $tagContentArgs = $r['args'];
                    }
                    
                    $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                    $cursor = $tagStartingPos + strlen($result);
                    break;

                case 'else':
                    $result = '';
                    if (!$lastIfEvaluation) {
                        // resolve any child tags in the current content before we process.
                        if ($debug) {echo "resolving tag content<br />\n<blockquote>";}
                        $r = $this->ResolveTemplate($app, $tagContent);
                        if ($debug) {echo "</blockquote>done<br />\n";}
                        $result = $r['content'];
                        $tagContentArgs = $r['args'];
                    }
                    $lastIfEvaluation = false;
                    
                    $template = substr_replace($template, $result, $tagStartingPos, ($cursor-$tagStartingPos));
                    $cursor = $tagStartingPos + strlen($result);
                    break;
                    
                default:
                    // unknown tag type
                    break;
            }
        }
        
        if ($tagLevel != 0) {
            // there was an error. replace tag buffer w/ error text
        }

        // return results
        return ['content' => $template, 'args' => $contentArgs];
    }
}