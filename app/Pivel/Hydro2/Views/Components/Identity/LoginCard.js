class LoginCard extends MultiPageCard {
    id = '';
    login_email = null;
    login_password = null;
    change_current = null;
    change_new = null;
    reset_email = null;
    constructor(id) {
        super(id+"_multipagecard");
        this.id = id;

        this.login_email = new FormField(id+"_loginform_email");
        this.login_password = new LabelledFormPasswordField(id+"_loginform_password");
        this.change_current = new LabelledFormPasswordField(id+"_changepasswordform_current-password");
        this.change_new = new LabelledFormPasswordField(id+"_changepasswordform_new-password");
        this.reset_email = new FormField(id+"_requestresetpasswordform_email");

        this._e.Nodes(id+"_loginform").AddEventHandler("submit", this._onLoginFormSubmit.bind(this));
        this._e.Nodes(id+"_requestresetpasswordform").AddEventHandler("submit", this._onResetFormSubmit.bind(this));
        this._e.Nodes(id+"_changepasswordform").AddEventHandler("submit", this._onChangePasswordFormSubmit.bind(this));
        // check whether we are connected via https. If not, display a warning to use https
    }

    _onLoginFormSubmit(event) {
        event.preventDefault();
        console.log("login clicked");
        this.SubmitLoginForm();
        return false;
    }

    _onResetFormSubmit(event) {
        event.preventDefault();
        this.SubmitResetForm();
        return false;
    }

    _onChangePasswordFormSubmit(event) {
        event.preventDefault();
        this.SubmitChangePasswordForm();
        return false;
    }

    SubmitLoginForm() {
        // disable submit button and change contents to spinner
        this._e.Nodes(this.id+"_loginform_submit").Disable();
        this.login_email.HideValidation();
        this.login_password.HideValidation();
        // submit POST to ~login (this.$loginCallback)
        var request = new H.AjaxRequest("POST", "/api/hydro2/identity/login");
        request.SetJsonData({
            "email": this._e.Nodes(this.id+"_loginform_email").Value(),
            "password": this._e.Nodes(this.id+"_loginform_password").Value()
        });
        request.Send(this._submitLoginCallback.bind(this));
    }

    SubmitResetForm() {
        // disable submit button and change contents to spinner
        this._e.Nodes(this.id+"_requestresetpasswordform_submit").Disable();
        var request = new H.AjaxRequest("POST", "/api/hydro2/identity/sendpasswordreset");
        request.SetJsonData({
            "email": this._e.Nodes(this.id+"_requestresetpasswordform_email").Value()
        });
        request.Send(this._submitResetCallback.bind(this));
    }

    SubmitChangePasswordForm() {
        // disable submit button and change contents to spinner
        // session cookie should have already been set.
        this._e.Nodes(this.id+"_changepasswordform_submit").Disable();
        var request = new H.AjaxRequest("POST", "/api/hydro2/identity/changeuserpassword");
        request.SetJsonData({
            "password": this._e.Nodes(this.id+"_changepasswordform_current-password").Value(),
            "new_password": this._e.Nodes(this.id+"_changepasswordform_new-password").Value()
        });
        request.Send(this._submitChangePasswordCallback.bind(this));
    }

    /**
     * @param {H.AjaxResponse} response
     */
    _submitLoginCallback(response) {
        if (response.Status == H.StatusCode.NoContent) {
            var result = response.ResponseObject;
            // the session token and key should be saved in a cookie (httponly so we can't read it here)
            //  if 2FA challenge is required, display 2FA challenge screen
            // TODO implement 2FA challenge
            //this.NavigateTo("mfachallenge");
            //  if password change is required, display password change screen
            //'login_result' => [
            //    'authenticated' => true,
            //    'challenge_required' => ($user->Role->ChallengeIntervalMinutes>0),
            //    'password_change_required' => $userPassword->IsExpired(),
            //],
            if (result["authenticated"]) {
                if (result["password_change_required"]) {
                    this.NavigateTo("changepassword");
                } else {
                    // else refresh page. since logged in now, server should redirect to ?next arg
                    window.location.hash = "";
                    location.reload();
                }
            }
            
            this._e.Nodes(this.id+"_loginform_submit").Enable();
            return;
        }

        if (response.Status == H.StatusCode.InternalServerError) {
            this.login_email.SetValidation(false, "There was a problem with the server.");
        } else if (response.Status == H.StatusCode.BadRequest) {
            switch (response.ResponseObject.Code) {
                case "session-0003":
                    this.login_email.SetValidation(false, "Incorrect email.");
                    break;
                case "session-0004":
                case "session-0008":
                    this.login_email.SetValidation(false, "Unable to log in. Please contact the administrator.");
                    break;
                case "session-0005":
                    this.login_email.SetValidation(false, "This account is locked due to too many failed login attempts.");
                    break;
                case "session-0006":
                    this.login_email.SetValidation(false, "Account creation is incomplete. A validation email has been re-sent to your email address.");
                    break;
                case "session-0007":
                    this.login_password.SetValidation(false, "Incorrect password.");
                    break;
                default:
                    console.log(response);
                    this.login_email.SetValidation(false, "There was an unknown error.");
                    break;
            }
        } else {
            console.log(response);
            this.login_email.SetValidation(false, "There was an unknown error.");
        }
        
        //  restore submit button
        this._e.Nodes(this.id+"_loginform_submit").Enable();
    }

    /**
     * @param {H.AjaxResponse} response
     */
     _submitResetCallback(response) {
        if (response.Status == H.StatusCode.OK) {
            // switch to #resetlinksent
            this.NavigateTo("resetlinksent");
            this._e.Nodes(this.id+"_requestresetpasswordform_submit").Enable();
            return;
        }

        if (response.Status == H.StatusCode.NotFound) {
            this.reset_email.SetValidation(false, "The provided email address does not match an account.");
        } else if (response.Status == H.StatusCode.InternalServerError) {
            this.reset_email.SetValidation(false, "There was a problem with the server.");
        } else {
            console.log(response);
            this.reset_email.SetValidation(false, "There was an unknown error.");
        }
        
        //  restore submit button
        this._e.Nodes(this.id+"_requestresetpasswordform_submit").Enable();
    }

    /**
     * @param {H.AjaxResponse} response
     */
     _submitChangePasswordCallback(response) {
        if (response.Status == H.StatusCode.OK) {
            this.NavigateTo("changepasswordsuccess");
            window.location.hash = "";
            location.reload();
            this._e.Nodes(this.id+"_changepasswordform_submit").Enable();
            return;
        }

        // display feedback message
        if (response.Status == H.StatusCode.NotFound) {
            this.change_current.SetValidation(false, "There is no matching account found.");
        } else if (response.Status == H.StatusCode.InternalServerError) {
            this.change_current.SetValidation(false, "There was a problem with the server.");
        } else if (response.Status == H.StatusCode.BadRequest) {
            switch (response.ResponseObject.Code) {
                case "users-0012":
                    this.change_current.SetValidation(false, "Incorrect password. Please enter your current password.");
                    break;
                default:
                    console.log(response);
                    this.change_current.SetValidation(false, "There was an unknown error.");
                    break;
            }
        } else {
            console.log(response);
            this.change_current.SetValidation(false, "There was an unknown error.");
        }
        
        //  restore submit button
        this._e.Nodes(this.id+"_changepasswordform_submit").Enable();
    }
}