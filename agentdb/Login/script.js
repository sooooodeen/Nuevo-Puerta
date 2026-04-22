function validateForm() {
    let accountNumber = document.getElementById("account_number").value;
    let password = document.getElementById("password").value;
    if (accountNumber === "" || password === "") {
        showFeedback('error', 'Please fill in both fields');
        return false;
    }
    return true;
}
