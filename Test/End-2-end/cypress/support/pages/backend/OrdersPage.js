import MagentoRestApi from "Services/MagentoRestApi";

const magentoRestApi = new MagentoRestApi();

export default class OrdersPage {
    openLatestOrder() {
        cy.visit('/admin/sales/order/index/');

        cy.get('.admin__data-grid-wrap tbody tr:first').contains('View').click();

        cy.get('.fetch-mollie-payment-status').click();
    }

    openOrderById(id) {
        cy.visit('/admin/sales/order/view/order_id/' + id);
    }

    callFetchStatus() {
        cy.get('.fetch-mollie-payment-status').click();

        cy.wait(1000);

        cy.get('.fetch-mollie-payment-status').click();

        cy.wait(1000);
    }

    assertOrderStatusIs(status) {
        cy.get('#order_status').contains(status);
    }

    assertMolliePaymentStatusIs(status) {
        cy.get('.mollie-payment-status').contains(status);
    }

    assertOrderHasInvoice(orderId, count = 1) {
        magentoRestApi.getInvoicesByOrderId(orderId)
            .then(response => {
                expect(response.total_count).to.equal(count);
            });
    }

    assertOrderHasNoInvoices(orderId) {
        magentoRestApi.getInvoicesByOrderId(orderId)
            .then(response => {
                expect(response.total_count).to.equal(0);
            });
    }

    ship() {
        cy.get('#order_ship').should('be.enabled').click();

        cy.url().should('include', '/admin/order_shipment/new/order_id/');
    }
}
