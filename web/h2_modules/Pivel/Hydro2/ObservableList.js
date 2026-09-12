import EventEmitter from "./EventEmitter.js";

class ObservableList extends EventEmitter {
    _elements = [];

    /**
     * Add an element to the end of the list
     * @param element Element to add to list
     */
    push(element) {
        this._elements.push(element);
        this.emit('added', element);
    }

    /**
     * Remove element from the list
     */
    remove(element) {
        this._elements = this._elements.filter(e => e !== element);
        this.emit('removed', element);
    }

    getAt(index) {
        return this._elements[index];
    }

    setAt(index, element) {
        const old = this._elements[index];
        this._elements[index] = element;
        this.emit('updated', { oldElement: old, newElement: element });
    }

    length() {
        return this._elements.length;
    }
    
    forEach(callback) {
        this._elements.forEach(callback);
    }
}

export default ObservableList;