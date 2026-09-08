class ResetPasswordCard extends MultiPageCard {
    id = '';
    reset_new = null;
    constructor(id) {
        super(id+"_multipagecard");
        this.id = id;

        this.reset_new = new LabelledFormPasswordField(id+"_resetpasswordform_new-password");

        this._e.Nodes(id+"_resetpasswordform").AddEventHandler("submit", this._onResetFormSubmit.bind(this));
        // check whether we are connected via https. If not, display a warning to use
        //  https
    }

    _onResetFormSubmit(event) {
        event.preventDefault();
        this.SubmitResetForm();
        return false;
    }

    SubmitResetForm() {
        // disable submit button and change contents to spinner
        this._e.Nodes(this.id+"_resetpasswordform_submit").Disable();
        var id = this._e.Nodes(this.id+"_resetpasswordform_userid").Value();
        var request = new H.AjaxRequest("POST", "/api/hydro2/identity/users/"+id+"/changepassword");
        request.SetJsonData({
            "reset_token": this._e.Nodes(this.id+"_resetpasswordform_resettoken").Value(),
            "new_password": this._e.Nodes(this.id+"_resetpasswordform_new-password").Value()
        });
        request.Send(this._submitResetCallback.bind(this));
    }

    /**
     * @param {H.AjaxResponse} response
     */
     _submitResetCallback(response) {
        if (response.Status == H.StatusCode.NoContent) {
            this.NavigateTo("resetpasswordsuccess");
            this._e.Nodes(this.id+"_resetpasswordform_submit").Enable();
            return;
        }

        // display feedback message
        if (response.Status == H.StatusCode.InternalServerError) {
            this.reset_new.SetValidation(false, "There was a problem with the server.");
        } else if (response.Data["validation_errors"][0]["message"] == "The provided password reset token is incorrect, expired, or already used.") {
            this.reset_new.SetValidation(false, "Unable to change password. Invalid, expired, or already used password reset token.");
        } else {
            console.log(response);
            this.reset_new.SetValidation(false, "There was an unknown error.");
        }

        
        
        //  restore submit button
        this._e.Nodes(this.id+"_resetpasswordform_submit").Enable();
    }
}