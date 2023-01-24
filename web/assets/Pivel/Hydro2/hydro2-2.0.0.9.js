var H = {
    AjaxResponse: class {
        Status;
        ResponseText;

        constructor(statusCode, responseText) {
            this.Status = statusCode;
            this.ResponseText = responseText;
        }
    },

    AjaxRequest: class {
        Body = "";
        Headers = {};

        constructor(method, url) {
            this.Method = method;
            this.Url = url;
        }

        // TODO adding files

        // TODO implement this
        SetQueryData(queryData) {

        }

        // TODO implement this
        SetFormData(formData) {

        }

        SetJsonData(object) {
            this.Body = JSON.stringify(object);
            this.SetHeader("Content-Type", "application/json; charset=utf-8");
        }

        SetHeader(name, value) {
            this.Headers[name] = value;
        }

        Send(callback) {
            const req = new XMLHttpRequest();
            req.addEventListener("load", function () {
                // build response
                console.log(this.status, this.responseText);
                var response = new H.AjaxResponse(this.status, this.responseText);
                callback(response);
            });
            req.open(this.Method, this.Url);
            Object.keys(this.Headers).forEach(name => {
                req.setRequestHeader(name, this.Headers[name]);
            });
            req.send(this.Body);
        }
    },

    // TODO add remaining status codes
    StatusCode: {
        OK: 200,
    },

    Nodes: function(selector, parent=null) {
        return new H.DOMNodes(selector, parent);
    },

    DOMNodes: class {
        _nodeList = [];
        constructor(selector, parent=null) {
            if (selector instanceof HTMLElement || selector instanceof Document) {
                this._nodeList = [selector];
                return;
            }
            if (parent == null) {
                parent = document;
            }
            this._nodeList = parent.querySelectorAll(selector);
        }

        Enable() {
            this._nodeList.forEach(element => {
                element.disabled = false;
            });
        }

        Disable() {
            this._nodeList.forEach(element => {
                element.disabled = true;
            });
        }

        Value(newValue=null) {
            var values = [];
            this._nodeList.forEach(element => {
                values.push(element.value);
                if (newValue != null) {
                    element.value = newValue;
                }
            });

            if (values.length == 1) {
                return values[0];
            }

            return values;
        }

        Data(key, newValue=null) {
            var values = [];
            this._nodeList.forEach(element => {
                // TODO check if element.dataset contains key
                values.push(element.dataset[key]);
                if (newValue != null) {
                    element.dataset[key] = newValue;
                }
            });

            if (values.length == 1) {
                return values[0];
            }

            return values;
        }

        AddClass(className) {
            this._nodeList.forEach(element => {
                element.classList.add(className);
            });
        }

        RemoveClass(className) {
            this._nodeList.forEach(element => {
                element.classList.remove(className);
            });
        }

        HasClass(className) {
            var results = [];
            this._nodeList.forEach(element => {
                results.push(element.classList.contains(className));
            });

            if (results.length == 1) {
                return results[0];
            }

            return results;
        }

        AddEventHandler(event, callback) {
            this._nodeList.forEach(element => {
                element.addEventListener(event, callback);
            });
        }

        RemoveEventHandler(event, callback) {
            this._nodeList.forEach(element => {
                element.removeEventListener(event, callback);
            });
        }

        Nodes(selector) {
            // TODO how to handle when we have multiple nodes?
            return H.Nodes(selector, this._nodeList[0]);
        }
    }
}