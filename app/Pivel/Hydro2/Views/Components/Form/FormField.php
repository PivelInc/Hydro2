<?php

namespace Pivel\Hydro2\Views\Components\Form;

use Pivel\Hydro2\Extensions\RequireScript;
use Pivel\Hydro2\Extensions\RequireStyle;
use Pivel\Hydro2\Views\BaseView;

#[RequireStyle('FormField.css')]
#[RequireScript('FormField.js')]
class FormField extends BaseView
{

    public function __construct(
        protected ?string $Input=null,
        protected ?string $Name=null,
        protected ?string $Type=null,
        protected ?string $IdPrefix=null,
        protected ?string $AutoComplete=null,
        protected ?string $Title=null,
        protected ?string $Placeholder=null,
        protected ?string $Value=null,
    ) {
        $this->IdPrefix ??= bin2hex(random_bytes(16));
        $this->Placeholder ??= $this->Title;
    }
}