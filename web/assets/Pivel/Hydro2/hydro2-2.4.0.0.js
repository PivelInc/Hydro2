var H = {
    AjaxResponse: class {
        Status;
        ResponseText;
        ResponseObject;
        IsErrorResponse = false;

        constructor(statusCode, responseText) {
            this.Status = statusCode;
            this.ResponseText = responseText;
            try {
                this.ResponseObject = JSON.parse(responseText);
                if ("code" in this.ResponseObject) {
                    this.IsErrorResponse = true;
                    var er = new H.ErrorResponse();
                    er.Code = this.ResponseObject["code"];
                    if ("message" in this.ResponseObject) {
                        er.Message = this.ResponseObject["message"];
                    }
                    if ("detail" in this.ResponseObject) {
                        er.Detail = this.ResponseObject["detail"];
                    }
                    if ("help" in this.ResponseObject) {
                        er.Help = this.ResponseObject["help"];
                    }
                    this.ResponseObject = er;
                }
            } catch {
                this.ResponseObject = null;
            }
        }
    },

    ErrorResponse: class {
        Code = null;
        Message = null;
        Detail = null;
        Help = null;
    },

    AjaxRequest: class {
        Body = "";
        Headers = {};

        constructor(method, url) {
            this.Method = method;
            this.Url = url;
        }

        // TODO adding files

        SetQueryData(queryData) {
            // for each key in queryData
            // queryPart[] = urlencode(key)=urlencode(queryData[key])
            var queryParts = [];
            for (var key in queryData) {
                queryParts.push(""+encodeURIComponent(key)+"="+encodeURIComponent(queryData[key]));
            }

            // join queryParts with '&'
            var query = queryParts.join("&");

            // remove any query part in this.Url
            this.Url = this.Url.split("?")[0];

            // append new query part to this.Url
            this.Url += "?" + query;
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

    HtmlEncode: function(s) {
        var el = document.createElement('div');
        el.innerText = el.textContent = s;
        return el.innerHTML;
    },
    
    HtmlDecode: function(s) {
        var el = document.createElement('div');
        el.innerHtml = s;
        return el.innerText;
    },

    Nodes: function(selector, parent=null) {
        return new H.DOMNodes(selector, parent);
    },

    DOMNodes: class {
        _nodeList = [];
        constructor(selector, parent=null) {
            // TODO create new element when selector is like <tag>
            if (selector instanceof H.DOMNodes) {
                this._nodeList = selector._nodeList;
                return;
            }
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
                if (element.type == "checkbox") {
                    values.push(element.checked);
                } else {
                    values.push(element.value);
                }
                if (newValue != null) {
                    if (element.type == "checkbox") {
                        element.checked = newValue;
                    } else {
                        element.value = newValue;
                    }
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

        Attribute(attributeName, newValue=null) {
            var values = [];
            this._nodeList.forEach(element => {
                values.push(element.getAttribute(attributeName));
                if (newValue != null) {
                    element.setAttribute(attributeName, newValue);
                }
            });

            if (values.length == 1) {
                return values[0];
            }

            return values;
        }

        HTML(newHTML=null) {
            var values = [];
            this._nodeList.forEach(element => {
                values.push(element.innerHTML);
                if (newHTML != null) {
                    element.innerHTML = newHTML;
                }
            });

            if (values.length == 1) {
                return values[0];
            }

            return values;
        }

        Text(newText=null) {
            var values = [];
            this._nodeList.forEach(element => {
                values.push(element.innerText);
                if (newText != null) {
                    element.innerText = element.textContent = newText;
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

        Parent() {
            // returns next parent of this node
            // TODO how to handle when we have multiple nodes?
            return H.Nodes(this._nodeList[0].parentElement);
        }

        Count() {
            return this._nodeList.length;
        }
    },

    Tidal: class {
        static _tidal = null;
        _host = null;
        _port = null;
        _socket = null;
        isConnected = false;
        /**
         * @var {H.TidalHandler[]} _handlers
         */
        _handlers = [];
        _subscriptions = [];
        constructor(host, port) {
            this._host = host;
            this._port = port;
            try {
                this._socket = new WebSocket("ws://" + host + ":" + port);
                console.log('WebSocket - status '+this._socket.readyState);
                this._socket.onopen = this.onSocketOpen.bind(this);
                this._socket.onmessage = this.onSocketMessageReceived.bind(this);
                this._socket.onclose = this.onSocketClose.bind(this);
            }
            catch(ex){
                console.log(ex); 
            }
        }

        onSocketOpen() {
            this.isConnected = true;
            console.log("Welcome - status "+this._socket.readyState);
            this.emit("connected");
        };

        onSocketClose() {
            this.isConnected = false;
            console.log("Disconnected - status "+this._socket.readyState);
            this._socket = null;
            this.emit("disconnected");
        }

        onSocketMessageReceived(msg) {
            console.log("Received: "+msg.data);

            // json decode msg.data, if failure log to console and return
            try {
                var data = JSON.parse(msg.data);
            } catch (ex) {
                console.error(ex);
                return;
            }

            // check if data has "event" property, if not log to console and return
            if (!("event" in data)) {
                console.error("Received message without event property");
                console.log(data);
                return;
            }

            // check if there are any handlers for this event, and if so, call their onReceived callback with the data
            var subs = this._handlers.filter(sub => sub.event == data.event);
            for (var sub of subs) {
                try {
                    sub.onReceived(data);
                } catch (ex) {
                    console.error(ex);
                }
            }
        }

        publishEvent(event, data) {
            if (!this.isConnected) {
                console.error("Tidal is not connected");
                return;
            }

            var msg = {
                event: event,
                data: data
            };

            this._socket.send(JSON.stringify(msg));
        }

        subscribeEvent(event) {
            if (!this.isConnected) {
                console.error("Tidal is not connected");
                return;
            }

            if (this._subscriptions.includes(event)) {``
                return;
            }

            // {'subscribe': ['event1', 'event2']}
            var msg = {
                'subscribe': [event]
            };

            this._socket.send(JSON.stringify(msg));
            this._subscriptions.push(event);
        }

        cleanSubscriptions() {
            if (!this.isConnected) {
                console.error("Tidal is not connected");
                return;
            }

            // {'unsubscribe': ['event1', 'event2']}
            var msg = {
                'unsubscribe': []
            };

            for (var event of this._subscriptions) {
                // if event is not in any of the handlers, add it to unsub message
                if (!this._handlers.some(sub => sub.event == event)) {
                    msg['unsubscribe'].push(event);
                }
            }

            this._socket.send(JSON.stringify(msg));
        }

        _events = {};

        /**
         * Emit an event to any registered listeners.
         * @param {string} event 
         * @param  {...any} payload
         */
        emit(event, ...payload) {
            if (!this._events[event]) {
                return;
            }

            this._events[event].forEach(callback => {
                setTimeout(() => callback(...payload), 0); // Call asynchronously
            });
        }

        /**
         * Register an event listener.
         * @param {string} event 
         * @param {function} callback 
         */
        on(event, callback) {
            if (!this._events[event]) {
                this._events[event] = [];
            }

            this._events[event].push(callback);
        }

        /**
         * Remove an event listener.
         * @param {string} event 
         * @param {function} callback
         */
        off(event, callback) {
            if (!this._events[event]) {
                return;
            }

            this._events[event] = this._events[event].filter(cb => cb !== callback);
        }

        static Connect(token, host=null, port=8080) {
            if (this._tidal != null && this._tidal.isConnected) {
                return;
            }
            
            // if host is null, use window.location.hostname
            if (host == null) {
                host = window.location.hostname;
            }

            this._tidal = new H.Tidal(host, port);

            this._tidal.on("connected", () => {
                console.log("Tidal connected");
                this._tidal._socket.send(JSON.stringify({ 'token': token }));
                this._tidal.emit("ready");
            });
        }

        static Disconnect() {
            if (this._tidal == null) {
                return;
            }

            this._tidal._socket.close();
            this._tidal.isConnected = false;
            this._tidal = null;
        }

        static AddHandler(event, onReceived) {
            if (this._tidal == null) {
                console.error("Tidal is not initialized");
                return null;
            }

            var handler = new H.TidalHandler(event, onReceived);
            this._tidal._handlers.push(handler);
            this._tidal.subscribeEvent(event);
            return handler;
        }

        static RemoveHandler(handler) {
            if (this._tidal == null) {
                console.error("Tidal is not initialized");
                return;
            }

            var index = this._tidal._handlers.indexOf(handler);
            if (index > -1) {
                this._tidal._handlers.splice(index, 1);
            }

            this._tidal.cleanSubscriptions();
        }

        static Publish(event, data) {
            if (this._tidal == null) {
                console.error("Tidal is not initialized");
                return;
            }

            this._tidal.publishEvent(event, data);
        }
    },

    TidalHandler: class {
        event;
        onReceived;
        constructor(event, onReceived) {
            this.event = event;
            this.onReceived = onReceived;
        }
    },

    StatusCode: {
        Continue: 100,
        SwitchingProtocols: 101,
        Processing: 102,
        EarlyHints: 103,
        
        OK: 200,
        Created: 201,
        Accepted: 202,
        NonAuthoritativeInformation: 203,
        NoContent: 204,
        ResetContent: 205,
        PartialContent: 206,
        MultiStatus: 207,
        AlreadyReported: 208,
        IMUsed: 226,

        MultipleChoices: 300,
        MovedPermanently: 301,
        Found: 302,
        SeeOther: 303,
        NotModified: 304,
        UseProxy: 305,
        //SwitchProxy: 306,
        TemporaryRedirect: 307,
        PermanentRedirect: 308,

        BadRequest: 400,
        Unauthorized: 401,
        PaymentRequired: 402,
        Forbidden: 403,
        NotFound: 404,
        MethodNotAllowed: 405,
        NotAcceptable: 406,
        ProxyAuthenticationRequired: 407,
        RequestTimeout: 408,
        Conflict: 409,
        Gone: 410,
        LengthRequired: 411,
        PreconditionFailed: 412,
        PayloadTooLarge: 413,
        URITooLong: 414,
        UnsupportedMediaType: 415,
        RangeNotSatisfiable: 416,
        ExpectationFailed: 417,
        ImATeapot: 418,
        MisdirectedRequest: 421,
        UnprocessableEntity: 422,
        Locked: 423,
        FailedDependency: 424,
        TooEarly: 425,
        UpgradeRequired: 426,
        PreconditionRequired: 428,
        TooManyRequests: 429,
        RequestHeaderFieldsTooLarge: 431,
        UnavailableForLegalReasons: 451,

        InternalServerError: 500,
        NotImplemented: 501,
        BadGateway: 502,
        ServiceUnavailable: 503,
        GatewayTimeout: 504,
        HTTPVersionNotSupported: 505,
        VariantAlsoNegotiates: 506,
        InsufficientStorage: 507,
        LoopDetected: 508,
        NotExtended: 510,
        NetworkAuthenticationRequired: 511,
    },
}