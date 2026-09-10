class H2View extends HTMLElement {
    observedAttributes = ["is"];
    h2_component = null;
    state = 'loading';

    constructor() {
        super();
    }

    async connectedCallback() {
        // Custom element added to page
        await customElements.whenDefined('h2-view');
        var Component;
        try {
            const {default: c} = await import("/h2_modules/" + this.attributes["is"].value + ".js");
            Component = c;
        } catch (e) {
            this.state = 'error';
            if (!(e instanceof TypeError)) {
                throw e;
            }
            console.error("Couldn't fetch module for custom element " + this.attributes["is"].value + ".");
            return;
        }
        this.h2_component = new Component(this);
        this.state = 'defined';
    }

    async whenDefined() {
        // wait until state != 'loading'
        while (this.state === 'loading') {
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }

    disconnectedCallback() {
        console.log("Custom element removed from page.");
    }

    connectedMoveCallback() {
        console.log("Custom element moved with moveBefore()");
    }

    adoptedCallback() {
        console.log("Custom element moved to new page.");
    }

    attributeChangedCallback(name, oldValue, newValue) {
        console.log(`Attribute ${name} has changed.`);
    }
}

customElements.define("h2-view", H2View);

export default H2View;