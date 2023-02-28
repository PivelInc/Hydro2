<?php

namespace Package\Pivel\Hydro2\Core\Views;

use Package\Pivel\Hydro2\Core\Utilities;

class FallbackView extends BaseWebView
{
    

    public function __construct(
        protected ?string $CoreVersion=null,
    ) {
        $v = Utilities::getPackageManifest()['Pivel']['Hydro2']['version'];
        $this->CoreVersion = join('.', $v);
    }
}