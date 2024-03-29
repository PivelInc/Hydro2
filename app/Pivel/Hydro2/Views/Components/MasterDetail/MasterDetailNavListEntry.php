<?php

namespace Pivel\Hydro2\Views\Components\MasterDetail;

use Pivel\Hydro2\Views\BaseView;

class MasterDetailNavListEntry extends BaseView
{
    protected bool $HasChildren = false;
    protected ?MasterDetailNavList $NavList = null;

    public function __construct(
        protected string $Key,
        protected string $Name,
        protected array $ChildNavTree,
    ) {
        if (count($this->ChildNavTree) != 0) {
            $this->HasChildren = true;
            $this->NavList = new MasterDetailNavList($this->ChildNavTree);
        }
    }
}