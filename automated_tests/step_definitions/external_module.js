//Add any of your own step definitions here
const { Given } = require('@badeball/cypress-cucumber-preprocessor')


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I click on the SDV button labeled {string} for the field {string}
 * @param {string} label - button text in the field's SDV badge, e.g. 'Verify', 'Unverify', 'Raise query'
 * @param {string} field - variable name of the field
 * @description clicks a button in the SDV badge shown under the field label
 */
Given('I click on the SDV button labeled {string} for the field {string}', (label, field) => {
    cy.get(`.cctc-sdv-badge[data-field="${field}"]`).contains('button', label).click()
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should see the SDV badge {string} for the field {string}
 * @param {string} text - text in the field's SDV badge, e.g. 'SDV verified', 'Changed since SDV', 'SDV required', 'Query open'
 * @param {string} field - variable name of the field
 * @description verifies the SDV badge under the field label contains the text
 */
Given('I should see the SDV badge {string} for the field {string}', (text, field) => {
    cy.get(`.cctc-sdv-badge[data-field="${field}"]`).should('contain', text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should NOT see the SDV badge {string} for the field {string}
 * @param {string} text - text that should not be in the field's SDV badge
 * @param {string} field - variable name of the field
 * @description verifies the SDV badge under the field label does not contain the text
 */
Given('I should NOT see the SDV badge {string} for the field {string}', (text, field) => {
    cy.get('#cctc-sdv-panel').should('exist')
    cy.get('body').then(($body) => {
        const badge = $body.find(`.cctc-sdv-badge[data-field="${field}"]`)
        if (badge.length) cy.wrap(badge).should('not.contain', text)
    })
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I enter {string} as the SDV query for the field {string}
 * @param {string} text - query text
 * @param {string} field - variable name of the field
 * @description types the monitor query into the box opened by the field's 'Raise query' button
 */
Given('I enter {string} as the SDV query for the field {string}', (text, field) => {
    cy.get(`#cctc-sdv-raise-text-${field}`).clear().type(text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I select the SDV requirement {string} for the field {string}
 * @param {string} requirement - 'required', 'optional' or 'excluded'
 * @param {string} field - variable name of the field
 * @description changes the field's SDV requirement from its badge on the data entry form (confirm dialog is accepted)
 */
Given('I select the SDV requirement {string} for the field {string}', (requirement, field) => {
    cy.on('window:confirm', () => true)
    cy.get(`.cctc-sdv-badge[data-field="${field}"] select.cctc-sdv-plan-select`).select(requirement)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I select the SDV plan requirement {string} for the field {string}
 * @param {string} requirement - 'required', 'optional' or 'excluded'
 * @param {string} field - variable name of the field
 * @description changes the field's SDV requirement on the SDV Plan page (confirm dialog is accepted)
 */
Given('I select the SDV plan requirement {string} for the field {string}', (requirement, field) => {
    cy.on('window:confirm', () => true)
    cy.get(`select.cctc-sdv-plan-select[data-field="${field}"]`).select(requirement)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should see the SDV form status {string}
 * @param {string} status - e.g. 'SDV pending', 'SDV in progress', 'SDV complete', 'SDV not required', 'Re-verification required'
 * @description verifies the form's SDV status shown in the SDV panel
 */
Given('I should see the SDV form status {string}', (status) => {
    cy.get('#cctc-sdv-form-status').should('have.text', status)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should see the SDV message {string}
 * @param {string} text - success or error message
 * @description verifies the message shown in the SDV panel after an action
 */
Given('I should see the SDV message {string}', (text) => {
    cy.get('#cctc-sdv-message').should('be.visible').and('contain', text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I enter {string} as the SDV reason
 * @param {string} text - reason text
 * @description types the reason in the open 'not required' or 'reopen' reason box
 */
Given('I enter {string} as the SDV reason', (text) => {
    cy.get('.cctc-sdv-reason:visible textarea').clear().type(text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I select the SDV response {string} for the query on the field {string}
 * @param {string} response - one of the response codes
 * @param {string} field - variable name of the queried field
 * @description chooses the site response for the field's open monitor query
 */
Given('I select the SDV response {string} for the query on the field {string}', (response, field) => {
    cy.get(`#cctc-sdv-queries tr[data-field="${field}"] select.cctc-sdv-response`).select(response)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I enter {string} as the SDV response comment for the field {string}
 * @param {string} text - comment
 * @param {string} field - variable name of the queried field
 * @description types the response comment for the field's open monitor query
 */
Given('I enter {string} as the SDV response comment for the field {string}', (text, field) => {
    cy.get(`#cctc-sdv-queries tr[data-field="${field}"] textarea[id^=cctc-sdv-response-comment-]`).clear().type(text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I enter {string} as the SDV re-raise text for the field {string}
 * @param {string} text - new query text
 * @param {string} field - variable name of the queried field
 * @description replaces the query text used when re-raising the field's query
 */
Given('I enter {string} as the SDV re-raise text for the field {string}', (text, field) => {
    cy.get(`#cctc-sdv-queries tr[data-field="${field}"] textarea[id^=cctc-sdv-reraise-text-]`).clear().type(text)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I click on the SDV query button labeled {string} for the field {string}
 * @param {string} label - e.g. 'Send response', 'Accept', 'Re-raise', 'Close query'
 * @param {string} field - variable name of the queried field
 * @description clicks a button in the field's row of the SDV query table
 */
Given('I click on the SDV query button labeled {string} for the field {string}', (label, field) => {
    cy.get(`#cctc-sdv-queries tr[data-field="${field}"]`).contains('button', label).click()
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should see {int} row(s) in the SDV dashboard
 * @param {int} count - number of form rows
 * @description verifies the number of rows in the SDV dashboard forms table
 */
Given('I should see {int} row(s) in the SDV dashboard', (count) => {
    cy.get('#cctc-sdv-forms-table tr[data-record]').should('have.length', count)
})


/**
 * @module SourceDataVerification
 * @author Mintoo Xavier <min2xavier@gmail.com>
 * @example I should NOT see the record {string} in the SDV dashboard
 * @param {string} record - record id
 * @description verifies the record does not appear in the SDV dashboard forms table
 */
Given('I should NOT see the record {string} in the SDV dashboard', (record) => {
    cy.get(`#cctc-sdv-forms-table tr[data-record="${record}"]`).should('not.exist')
})
