import { Controller } from '@hotwired/stimulus'
import '../styles/card.css';
export default class extends Controller {
    static targets = ['content', 'footer']
    static classes = ['expanded', 'loading', 'highlight']
    static values = {
        expandable: Boolean,
        expanded: Boolean,
        loading: Boolean,
        dismissible: Boolean
    }

    connect() {
        this.render()
    }

    toggle() {
        if (this.expandableValue) {
            this.expandedValue = !this.expandedValue
        }
    }

    expand() {
        if (this.expandableValue) {
            this.expandedValue = true
        }
    }

    collapse() {
        if (this.expandableValue) {
            this.expandedValue = false
        }
    }

    dismiss() {
        if (this.dismissibleValue) {
            this.element.style.transition = 'opacity 0.3s ease'
            this.element.style.opacity = '0'

            setTimeout(() => {
                this.element.remove()
                this.dispatch('dismissed')
            }, 300)
        }
    }

    highlight() {
        this.element.classList.add(...this.highlightClasses)

        setTimeout(() => {
            this.element.classList.remove(...this.highlightClasses)
        }, 2000)
    }

    setLoading(loading = true) {
        this.loadingValue = loading
    }

    expandedValueChanged() {
        this.render()
    }

    loadingValueChanged() {
        this.render()
    }

    render() {
        // Handle expanded state
        if (this.expandedValue) {
            this.element.classList.add(...this.expandedClasses)
            this.showContent()
        } else {
            this.element.classList.remove(...this.expandedClasses)
            if (this.expandableValue) {
                this.hideContent()
            }
        }

        // Handle loading state
        if (this.loadingValue) {
            this.element.classList.add(...this.loadingClasses)
            this.disableInteractions()
        } else {
            this.element.classList.remove(...this.loadingClasses)
            this.enableInteractions()
        }
    }

    showContent() {
        this.contentTargets.forEach(target => {
            target.style.display = 'block'
        })
    }

    hideContent() {
        this.contentTargets.forEach(target => {
            target.style.display = 'none'
        })
    }

    disableInteractions() {
        const buttons = this.element.querySelectorAll('button')
        buttons.forEach(button => button.disabled = true)
    }

    enableInteractions() {
        const buttons = this.element.querySelectorAll('button')
        buttons.forEach(button => button.disabled = false)
    }
}
