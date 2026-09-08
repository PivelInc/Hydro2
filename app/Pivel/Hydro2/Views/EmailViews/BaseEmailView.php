<?php

namespace Pivel\Hydro2\Views\EmailViews;

use Pivel\Hydro2\Hydro2;
use Pivel\Hydro2\Views\BaseView;
use ReflectionClass;

class BaseEmailView extends BaseView
{
    public function Render(Hydro2 $app, bool $isOuter=true) : string {
        $this->rc = new ReflectionClass($this);        
        $this->properties = array_map(fn($a) => $a->name,$this->rc->getProperties());
        $templatePath = str_replace('.php','.emailtemplate.html', $this->rc->getFileName());
        $template = file_get_contents($templatePath);
        $rendered = $this->ResolveTemplate($app, $template)['content'];
        $rendered = preg_replace("/<plaintext>[\s\S]*<\/plaintext>/", '', $rendered); // remove plaintext section from HTML render
        return $rendered;
    }

    public function RenderPlaintext(Hydro2 $app) : ?string {
        $this->rc = new ReflectionClass($this);        
        $this->properties = array_map(fn($a) => $a->name,$this->rc->getProperties());
        $templatePath = str_replace('.php','.emailtemplate.html', $this->rc->getFileName());
        $template = file_get_contents($templatePath);
        $rendered = $this->ResolveTemplate($app, $template)['content'];
        $matches = [];
        $rendered = preg_match("/(?<=<plaintext>)[\s\S]*(?=<\/plaintext>)/", $rendered, $matches); // remove plaintext section from HTML render
        if (count($matches) !== 1) {
            return null;
        }
        $plaintext = ltrim($matches[0]);
        return $plaintext;
    }

    /**
     * Tries to get subject line from template's <title></title> tag, otherwise returns null.
     */
    public function GetSubject(Hydro2 $app) : ?string {
        $this->rc = new ReflectionClass($this);        
        $this->properties = array_map(fn($a) => $a->name,$this->rc->getProperties());
        $templatePath = str_replace('.php','.emailtemplate.html', $this->rc->getFileName());
        $template = file_get_contents($templatePath);
        $rendered = $this->ResolveTemplate($app, $template)['content'];
        $matches = [];
        $rendered = preg_match("/(?<=<title>)[\s\S]*(?=<\/title>)/", $rendered, $matches); // remove plaintext section from HTML render
        if (count($matches) !== 1) {
            return null;
        }
        $subject = trim($matches[0]);
        return $subject;
    }
}