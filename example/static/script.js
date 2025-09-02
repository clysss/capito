const widget = document.querySelector("#cap");

widget.addEventListener("solve", function (e) {
    const token = e.detail.token;
    console.log("Captcha solved!");
    console.log("Token:"+token); // Token is returned by the server
    
    // Submit token to backend for validation
    validateToken(token);
});

// Function to validate token with backend
async function validateToken(token) {
    try {
        const response = await fetch('/validate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ token: token })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            alert('Verification successful');
        } else {
            alert('Verification failed');
        }
    } catch (error) {
        console.error('Validation error:', error);
        alert('Exception ' + error.message);
    }
}