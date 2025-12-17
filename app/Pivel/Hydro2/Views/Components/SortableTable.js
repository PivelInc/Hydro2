class SortableTable {
    _e;
    _table;
    _apiEndpoint;
    _loader;
    _headers;
    _columns = [];
    _sortedBy = null;
    _sortDir = 0; // 0=asc,1=desc
    _data = [];
    _customRenderer = null;
    constructor(selector, apiEndpoint, renderer=null) {
        this._e = H.Nodes(selector+".sortable-table");
        this._table = this._e.Nodes("table");
        this._apiEndpoint = apiEndpoint;
        this._customRenderer = renderer;

        // find columns
        this._headers = this._table.Nodes("th.sortable[data-property]");
        this._columns = this._table.Nodes("th[data-property]").Data("property");

        this._sortedBy = this._columns[0];
        this._sortDir = 0;
        
        // initial/default sort and load
        this.SetSortBy(this._sortedBy, this._sortDir);

        // add sort event handlers
        this._headers.AddEventHandler("click", this._sortHeaderClick.bind(this));
    }

    _sortHeaderClick(event) {
        var header = H.Nodes(event.target);
        var wasSortedBy = this._sortedBy;
        var newSortedBy = header.Data("property");
        var newSortDir = wasSortedBy == newSortedBy ? this._sortDir ^ 1 : 0; // XOR: 0^1 = 1, 1^1 = 0

        this.SetSortBy(newSortedBy, newSortDir);
    }

    SetSortBy(sortBy, sortDir, reload=true) {
        this._sortedBy = sortBy;
        this._sortDir = sortDir;

        // remove .sorted, .asc, and .desc classes from headers
        this._headers.RemoveClass("sorted");
        this._headers.RemoveClass("asc");
        this._headers.RemoveClass("desc");

        // add .sorted and .asc|.desc class to correct header
        var header = this._table.Nodes("th.sortable[data-property=\""+this._sortedBy+"\"]");
        header.AddClass("sorted");
        header.AddClass(this._sortDir === 0 ? "asc" : "desc");

        if (reload) {
            this.Load();
        }
    }

    Load() {
        this._showSpinner();
        var request = new H.AjaxRequest("GET", this._apiEndpoint);
        request.SetQueryData({
            "sort_by": this._sortedBy,
            "sort_dir": this._sortDir === 0 ? "asc" : "desc"
        });
        request.Send(this._dataLoadedCallback.bind(this));
    }

    _dataLoadedCallback(response) {
        if (response.Status == H.StatusCode.OK) {
            this._data = response.ResponseObject;
            this._hideSpinner();
            if (this._customRenderer !== null) {
                this._customRenderer(this);
            } else {
                this.RenderData();
            }
            return;
        }

        if (response.Status == H.StatusCode.InternalServerError) {
            console.warn("The API enpoint \""+this._apiEndpoint+"\" responded with 500 Internal Server Error.", response);
        } else if (response.Status == H.StatusCode.NotFound) {
            // TODO display no permission message
            console.warn("The API enpoint \""+this._apiEndpoint+"\" responded with 404 Not Found.", response);
        } else {
            console.warn("The API enpoint \""+this._apiEndpoint+"\" responded with an unexpected status code.", response);
        }
    }

    RenderData() {
        // if no data, display placeholder
        if (this._data.length == 0) {
            this._table.Nodes("tbody").HTML("<tr><td class=\"placeholder\" colspan=\"20\">No data</td></tr>");
            return;
        }
        var tbodyHtml = "";
        // for each row in this._data:
        for (var row of this._data) {
            // add <tr>
            tbodyHtml += "<tr>";
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
            // add </tr>
            tbodyHtml += "</tr>";
        }
        this._table.Nodes("tbody").HTML(tbodyHtml);
    }

    _showSpinner() {
        this._e.AddClass("loading");
    }

    _hideSpinner() {
        this._e.RemoveClass("loading");
    }
}