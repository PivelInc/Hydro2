// TODO should this extend H.DOMNodes ?
class RichSelect {
    select = null;
    feedback = null;
    isMultiple = false;
    constructor(id) {
        this._e = H.Nodes(id).Parent();

        this.select = this._e.Nodes("select")._nodeList[0];
        this.isMultiple = this.select.multiple;
        this.feedback = this._e.Nodes(".feedback");
    }

    SetValidation(valid, feedback="") {
        this.feedback.HTML(feedback);
        this._e.RemoveClass("valid");
        this._e.RemoveClass("invalid");
        this._e.AddClass(valid?"valid":"invalid");
        this._e.AddClass("show-feedback");
    }

    HideValidation() {
        this._e.RemoveClass("show-feedback");
        this._e.RemoveClass("valid");
        this._e.RemoveClass("invalid");
        this.feedback.HTML("");
    }

    SetOptions(options) {
        for (const o in this.select.options) {
            this.select.options.remove(0);
        }

        var oneSelected = false;
        options.forEach(element => {
            var o = new Option(element["text"], element["value"], (oneSelected&&!this.isMultiple)?false:element["selected"])
            oneSelected = oneSelected || element["selected"];
            this.select.options.add(o);
        });
    }

    Value(newValue=null) {
        if (newValue !== null && !Array.isArray(newValue)) {
            newValue = [newValue];
        }
        var v = [];
        var oneSelected = false;
        for (const o of this.select.options) {
            if (o.selected) {
                v.push(o.value);
            }

            if (newValue != null && this.isMultiple) {
                o.selected = newValue.includes(o.value);
                oneSelected = oneSelected || o.selected;
            }
        }

        if (!this.isMultiple) {
            return v.length > 0 ? v[0] : null;
        }

        return v;
    }

    Clear() {
        for (const o of this.select.options) {
            o.selected = false;
        };
    }
}