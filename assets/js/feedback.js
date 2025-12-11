// Simple feedback modal/notification system
function showFeedback(type, message) {
  let feedback = document.getElementById('feedback-modal');
  if (!feedback) {
    feedback = document.createElement('div');
    feedback.id = 'feedback-modal';
    feedback.style.position = 'fixed';
    feedback.style.top = '30px';
    feedback.style.left = '50%';
    feedback.style.transform = 'translateX(-50%)';
    feedback.style.zIndex = '9999';
    feedback.style.minWidth = '280px';
    feedback.style.maxWidth = '90vw';
    feedback.style.padding = '18px 32px';
    feedback.style.borderRadius = '8px';
    feedback.style.fontSize = '1.1em';
    feedback.style.boxShadow = '0 4px 16px rgba(0,0,0,0.12)';
    feedback.style.display = 'none';
    feedback.style.textAlign = 'center';
    document.body.appendChild(feedback);
  }
  let color = '#14532d', bg = '#e6f4ea';
  if (type === 'error') { color = '#fff'; bg = '#f44336'; }
  else if (type === 'success') { color = '#fff'; bg = '#4caf50'; }
  else if (type === 'info') { color = '#333'; bg = '#ffc107'; }
  feedback.style.background = bg;
  feedback.style.color = color;
  feedback.textContent = message;
  feedback.style.display = 'block';
  setTimeout(() => { feedback.style.display = 'none'; }, 3000);
}

// Usage: showFeedback('success', 'Your request was submitted!');
// Types: 'success', 'error', 'info'
