<?php

namespace Pivel\Hydro2\Views;

use Pivel\Hydro2\Extensions\RequireScript;
use Pivel\Hydro2\Extensions\RequireStyle;
use Pivel\Hydro2\Hydro2;
use ReflectionClass;

#[RequireScript('/assets/Pivel/Hydro2/hydro2-2.3.1.0.js', Inline: false)]
#[RequireStyle('/assets/Pivel/Hydro2/hydro2-2.3.1.0.css', Inline: false)]
class BaseWebView extends BaseView
{
    protected $Scripts = '';
    protected $Styles = '';

    protected $RequiredScripts = [];
    protected $RequiredStyles = [];

    public function __construct(
        protected ?string $Head=null,
        protected ?string $Title=null,
        protected ?string $Body=null,
    )
    {
        
    }

    /**
     * BaseWebView objects can have
     *  #[RequireScript('path')] and
     *  #[RequireStyle('path')]
     * attributes.
     * // TODO If a path with the same name but extensions .css or .js exist, they should also automatically be included.
     * The arrays of required script/style path names should be de-duplicated.
     * BaseWebView should have Script and Style properties that are filled in by the contents of included scripts/styles.
     * The RequireScript and RequireStyle attributes of the object's parent class(es) and any included views must also be included.
     * // TODO Each one should specify to be included inline (default) or via deferred <script>/<link rel="stylesheet"> tag.
     * //       if inline is selected, we'll check that the path exists as a file. If deferred, must rely be an accessible URI.
     *
     * Should child classes that extend BaseWebView be able to override the scripts?
     * 
     */
    public function Render(Hydro2 $app, bool $isOuter=true) : string {
        $renderedContent = parent::Render($app, isOuter:false);

        if (!$isOuter) {
            return $renderedContent;
        }

        // find attributes from each class
        foreach ($this->viewClassesUsed as $className) {
            $rc = new ReflectionClass($className);
            $fn = $rc->getFileName();
            $dir = dirname($fn);
            $scriptAttributes = $rc->getAttributes(RequireScript::class);
            $styleAttributes = $rc->getAttributes(RequireStyle::class);
            foreach ($scriptAttributes as $a) {
                /** @var RequireScript */
                $instance = $a->newInstance();
                if ($instance->Inline && file_exists($dir . '/' . $instance->Path)) {
                    $instance->Path = $dir . '/' . $instance->Path;
                }
                $this->RequiredScripts[$instance->Path] = ($this->RequiredScripts[$instance->Path] ?? false) || $instance->Inline;
            }
            foreach ($styleAttributes as $a) {
                /** @var RequireStyle */
                $instance = $a->newInstance();
                if ($instance->Inline && file_exists($dir . '/' . $instance->Path)) {
                    $instance->Path = $dir . '/' . $instance->Path;
                }
                $this->RequiredStyles[$instance->Path] = ($this->RequiredScripts[$instance->Path] ?? false) || $instance->Inline;
            }
        }

        //var_dump($this->RequiredScripts);
        //var_dump($this->RequiredStyles);
        $this->Scripts = '';
        $this->Styles = '';
        // compile scripts and styles
        foreach ($this->RequiredScripts as $path => $inline) {
            if (!$inline) {
                //echo 'hi';
                $this->Scripts .= "<script src=\"{$path}\"></script>\n";
                continue;
            }

            if (!file_exists($path)) {
                continue;
            }

            $s = file_get_contents($path);
            $this->Scripts .= "<script>{$s}</script>\n";
        }

        foreach ($this->RequiredStyles as $path => $inline) {
            if (!$inline) {
                $this->Styles .= "<link rel=\"stylesheet\" href=\"{$path}\" />\n";
                continue;
            }

            if (!file_exists($path)) {
                continue;
            }

            $s = file_get_contents($path);
            $this->Styles .= "<style>{$s}</style>\n";
        }

        // replace special template strings {!Scripts!} and {!Styles!}
        $renderedContent = str_replace('{!Scripts!}', $this->Scripts, $renderedContent);
        $renderedContent = str_replace('{!Styles!}', $this->Styles, $renderedContent);

        return $renderedContent;
    }
}