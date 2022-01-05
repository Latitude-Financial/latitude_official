document.addEventListener('DOMContentLoaded', function() {
    var paymentTermElem = document.querySelector('select[name="LATITUDE_FINANCE_PAYMENT_TERMS[]"]');
    var productElem = document.querySelector('select[name="LATITUDE_FINANCE_PRODUCT"]');
    appendErrorMessageWrapper();
    var paymentTermErrElem = document.getElementById("LATITUDE_FINANCE_PAYMENT_TERMS_ERROR");

    productElem.addEventListener('change', function (e) {
        e.preventDefault();
        refresh();
    });

    paymentTermElem.addEventListener('change', function (e) {
        e.preventDefault();
        refresh();
    });

    refresh();
    function refresh() {
        var shouldDisable = !productElem.value || productElem.value === 'LPAY';
        paymentTermElem.disabled = shouldDisable;

        if (!shouldDisable) {
            paymentTermElem.parentNode.parentNode.style.display = 'block';
            if (paymentTermElem.value.length) {
                paymentTermErrElem.style.display = 'none';
            } else {
                paymentTermErrElem.style.display = 'block';
            }
        } else {
            paymentTermErrElem.style.display = 'none';
            paymentTermElem.parentNode.parentNode.style.display = 'none';
        }
    }

    function appendErrorMessageWrapper() {
        var errorWrapper = document.getElementById('LATITUDE_FINANCE_PAYMENT_TERMS_ERROR');
        if (!errorWrapper) {
            errorWrapper = document.createElement("div");
            errorWrapper.classList.add('aler', 'alert-danger');
            errorWrapper.id  = 'LATITUDE_FINANCE_PAYMENT_TERMS_ERROR';
            errorWrapper.innerHTML = "You have to set at least one value for Payment Terms!";
            errorWrapper.style.display = 'none';
            paymentTermElem.parentNode.insertBefore(errorWrapper, paymentTermElem);
        }

    }
});