class RichTable extends SortableTable {
    _searchField;
    _searchButton;
    _contextMenu;
    _contextButtons;
    _hasContextMenu = false;
    _nextRowId = 0;
    _contextSelectedRowId;
    _contextOptionHandlers = {};
    _idKey;
    _showDetailHandler = null;
    _showEditHandler = null;

    _query;
    constructor(selector, apiEndpoint, renderer=null, idKey="id") {
        super(selector + ".rich-table", apiEndpoint, renderer);

        this._idKey = idKey;
        this._searchForm = this._e.Nodes(selector + "_search_form");
        this._searchField = this._e.Nodes(selector + "_search_form_q");
        this._searchButton = this._e.Nodes(selector + "_search_form_submit");
        this._createButton = this._e.Nodes(selector + "_create");
        this._createOverlay = this._e.Nodes(selector + "_create_overlay");
        this._detailOverlay = this._e.Nodes(selector + "_detail_overlay");
        this._editOverlay = this._e.Nodes(selector + "_edit_overlay");
        this._contextMenu = this._e.Nodes(selector + "_context");
        this._hasContextMenu = this._contextMenu.Count() == 1;
        this._toast = this._e.Nodes(selector + "_toast");
        this._overlayCloseButtons = this._e.Nodes(".close-overlay");

        // add search event handler
        this._searchForm.AddEventHandler("submit", this._searchClick.bind(this));
        // add create event handler
        this._createButton.AddEventHandler("click", this._createClick.bind(this));
        // add overlay close event handler(s)
        this._overlayCloseButtons.AddEventHandler("click", this._overlayCloseClick.bind(this));

        if (this._hasContextMenu) {
            // add event handlers for closing context menu - click, scroll, esc
            H.Nodes(document).AddEventHandler("click", this._closeContextMenuClick.bind(this));
            H.Nodes(document).AddEventHandler("wheel", this._closeContextMenuScroll.bind(this));
            H.Nodes(document).AddEventHandler("touchmove", this._closeContextMenuTouch.bind(this));
            H.Nodes(document).AddEventHandler("keydown", this._closeContextMenuKeydown.bind(this));
            // handle clicking context menu options
            this._contextMenu.Nodes(".context-menu-item").AddEventHandler("click", this._contextMenuOptionClick.bind(this));
            this._registerContextOptionHandler("details", this.ContextOptionDetails.bind(this));
            this._registerContextOptionHandler("edit", this.ContextOptionEdit.bind(this));
            this._registerContextOptionHandler("delete", this.ContextOptionDelete.bind(this));
        }
    }

    AddContextMenuOption(content, callback) {
        var key = "custom" + Object.keys(this._contextOptionHandlers).length;
        var li = document.createElement("li");
        var btn = document.createElement("button");
        btn.className = "context-menu-item";
        btn.dataset["option"] = key;
        btn.innerText = content;
        H.Nodes(btn).AddEventHandler("click", this._contextMenuOptionClick.bind(this));
        li.append(btn);
        this._contextMenu.Nodes("ul")._nodeList[0].append(li);
        this._registerContextOptionHandler(key, callback);
    }

    _searchClick(event) {
        event.preventDefault(); // we are in a form, don't let the form be submitted.
        this._query = this._searchField.Value();
        this.Load();
        return false;
    }

    _createClick(event) {
        event.preventDefault();
        this.HideOverlays();
        this.ShowCreateOverlay();
        return false;
    }

    _overlayCloseClick(event) {
        event.preventDefault();
        this.HideOverlays();
        return false;
    }

    _contextMenuOptionClick(event) {
        event.preventDefault();
        var optionKey = H.Nodes(event.currentTarget).Data("option");
        if (!Object.keys(this._contextOptionHandlers).includes(optionKey)) {
            console.error("No context option handler is registered for option \"" + optionKey + "\"");
            return false;
        }
        this._contextOptionHandlers[optionKey](this._data[this._contextSelectedRowId]);
        return false;
    }

    _registerContextOptionHandler(key, callback) {
        this._contextOptionHandlers[key] = callback;
    }

    ContextOptionDetails() {
        this.HideOverlays();
        this.ShowDetailOverlay();
    }

    ContextOptionEdit() {
        this.HideOverlays();
        this.ShowEditOverlay();
    }

    ContextOptionDelete() {
        this.HideOverlays();
        // show confirmation, with callback to send DELETE request.
        if (!confirm("Are you sure you want to delete the selected item?")) {
            return;
        }
        this._showSpinner();
        var request = new H.AjaxRequest("DELETE", this._apiEndpoint + "/" + this._data[this._contextSelectedRowId][this._idKey]);
        request.Send(this._dataDeletedCallback.bind(this));
    }

    _dataDeletedCallback(response) {
        if (response.Status == H.StatusCode.NoContent) {
            // display toast (success)
            this.ShowToast("Item deleted.", false, "success");
            // trigger table refresh. this will automatically hide the spinner.
            this.Load();
            // clear forms
            return;
        }

        this._hideSpinner();

        if (response.Status == H.StatusCode.InternalServerError) {
            this.ShowToast("There was a problem with the server.", false, "error");
        } else if (response.Status == H.StatusCode.NotFound) {
            this.ShowToast("You don't have permission to delete this item.", false, "error");
        } else {
            console.error(response);
            this.ShowToast("There was an unknown error.", false, "error");
        }
    }

    Load() {
        this._showSpinner();
        var request = new H.AjaxRequest("GET", this._apiEndpoint);
        var qd = {
            "sort_by": this._sortedBy,
            "sort_dir": this._sortDir === 0 ? "asc" : "desc"
        };
        if (this._query != undefined) {
            qd["q"] = this._query;
        }
        request.SetQueryData(qd);
        request.Send(this._dataLoadedCallback.bind(this));
    }

    ShowCreateOverlay() {
        this._createOverlay.AddClass("active");
    }

    ShowDetailOverlay() {
        if (this._showDetailHandler != null) {
            this._showDetailHandler(this._data[this._contextSelectedRowId]);
        }
        this._detailOverlay.AddClass("active");
    }

    ShowEditOverlay() {
        if (this._showEditHandler != null) {
            this._showEditHandler(this._data[this._contextSelectedRowId]);
        }
        this._editOverlay.AddClass("active");
    }

    HideOverlays() {
        this._createOverlay.RemoveClass("active");
        this._detailOverlay.RemoveClass("active");
        this._editOverlay.RemoveClass("active");
    }

    ShowToast(message, long=false, statusClass="default") {
        var t = this._toast;
        t.Nodes("span").Text(message);
        t._nodeList[0].style.width = "" + t._nodeList[0].scrollWidth + "px";
        t.AddClass("popped");
        t.AddClass(statusClass);

        setTimeout(function() {
            t.RemoveClass("popped");
            t._nodeList[0].style.width = 0;
        }, long ? 30000 : 5000);

        setTimeout(function() {
            t.RemoveClass(statusClass);
        }, long ? 30000 + 1000 : 5000 + 1000);
    }

    RenderData() {
        this._nextRowId = 0;
        // if no data, display placeholder
        if (this._data.length == 0) {
            if (this._hasContextMenu) {
                this._table.Nodes("tbody").HTML("<tr><td class=\"placeholder\" colspan=\"20\">No data</td><td></td></tr>");
            } else {
                this._table.Nodes("tbody").HTML("<tr><td class=\"placeholder\" colspan=\"20\">No data</td></tr>");   
            }
            return;
        }
        var tbodyHtml = "";
        // for each row in this._data:
        for (var row of this._data) {
            // add <tr>
            if (this._hasContextMenu) {
                tbodyHtml += "<tr data-row=\"" + this._nextRowId + "\">";
            } else {
                tbodyHtml += "<tr>";
            }
            // for each property in this._columns:
            for (var property of this._columns) {
                // add <td>row[property]</td> to tbodyHtml
                var value = row[property];
                if (value === true) {
                    value = "Yes";
                }
                if (value === false) {
                    value = "No";
                }
                if (value === null) {
                    value = "N/A";
                }
                tbodyHtml += "<td>" + H.HtmlEncode(value) + "</td>";
            }
            if (this._hasContextMenu) {
                tbodyHtml += "<td><button class=\"rich-table-context-btn\" title=\"More...\" data-row=\"" + this._nextRowId + "\">...</button></td>";
                this._nextRowId++;
            }
            // add </tr>
            tbodyHtml += "</tr>";
        }
        this._table.Nodes("tbody").HTML(tbodyHtml);

        if (this._hasContextMenu) {
            // attach event handlers to buttons
            this._contextButtons = this._table.Nodes(".rich-table-context-btn");
            this._contextButtons.AddEventHandler("click", this._contextMenuClick.bind(this));
            // attach event handlers to rows
            this._table.Nodes("tbody > tr").AddEventHandler("contextmenu", this._contextMenuClick.bind(this));
        }
    }

    /**
     * 
     * @param {PointerEvent} event 
     * @returns 
     */
    _contextMenuClick(event) {
        event.preventDefault();
        event.stopPropagation();
        this._contextSelectedRowId = H.Nodes(event.currentTarget).Data("row");

        // display context menu
        this._contextMenu.AddClass("active");
        this._contextMenu._nodeList[0].style.top = "";
        this._contextMenu._nodeList[0].style.bottom = "";
        this._contextMenu._nodeList[0].style.left = "";
        this._contextMenu._nodeList[0].style.right = "";
        var h = this._contextMenu._nodeList[0].offsetHeight;
        var w = this._contextMenu._nodeList[0].offsetWidth;
        var y = event.clientY;
        var x = event.clientX;
        // if y + height > screen height, then use bottom = screen height - y instead.
        if (y + h >= window.innerHeight) {
            this._contextMenu._nodeList[0].style.bottom = (window.innerHeight - y)+"px";
        } else {
            this._contextMenu._nodeList[0].style.top = y+"px";
        }
        // if x + width > screen width, then use right = screen width - x instead.
        if (x + w >= window.innerWidth) {
            this._contextMenu._nodeList[0].style.right = (window.innerWidth - x)+"px";
        } else {
            this._contextMenu._nodeList[0].style.left = x+"px";
        }

        return false;
    }

    _closeContextMenuClick(event) {
        this.CloseContextMenu();
    }

    _closeContextMenuScroll(event) {
        this.CloseContextMenu();
    }
    
    _closeContextMenuTouch(event) {
        this.CloseContextMenu();
    }

    _closeContextMenuKeydown(event) {
        if (event.key != "Escape") {
            return;
        }
        this.CloseContextMenu();
    }

    CloseContextMenu() {
        this._contextMenu.RemoveClass("active");
    }
}