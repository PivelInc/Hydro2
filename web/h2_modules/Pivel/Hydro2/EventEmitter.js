class EventEmitter {
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
}

export default EventEmitter;