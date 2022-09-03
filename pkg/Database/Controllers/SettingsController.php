<?php

namespace Package\Database\Controllers;

use Package\CCMS\Controllers\BaseController;
use Package\CCMS\Extensions\Route;
use Package\CCMS\Extensions\RoutePrefix;
use Package\CCMS\Models\HTTP\Method;
use Package\CCMS\Models\Response;

#[RoutePrefix('api/database/settings')]
class SettingsController extends BaseController
{
    #[Route(Method::POST, 'validatehost')]
    public function ValidateHost() : Response {
        // if database has already been configured and not logged in as admin, return 404

        // check if host is a database server that matches selected driver
    }

    #[Route(Method::POST, 'validateuser')]
    public function ValidateUser() : Response {
        // if database has already been configured and not logged in as admin, return 404

        // check if this is a valid user/password on the selected host

        // check that this database user has sufficient privileges
        
        // additionally, check whether user has sufficient privileges to create a new database
    }

    #[Route(Method::POST, 'getdatabases')]
    public function GetDatabases() : Response {
        // if database has already been configured and not logged in as admin, return 404

        // get a list of databases which this user has privileges on
    }

    #[Route(Method::POST, 'configure')]
    public function SaveConfiguration() : Response {
        // if database has already been configured and not logged in as admin, return 404

        // test host, user settings

        // check that selected database is valid, or create a new one if selected

        // save configuration settings to .ini file
    }
}