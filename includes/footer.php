<?php $__user = currentUser(); ?>
<?php if ($__user): ?>
  <button class="chat-fab" id="chatFab" title="Chat with us">💬</button>
  <div class="chat-window" id="chatWindow">
    <div class="chat-header">
      <span>⛪ Parish Assistant</span>
      <button id="chatClose">&times;</button>
    </div>
    <div class="chat-body" id="chatBody">
      <div class="chat-msg bot">Hello! I can help with Mass Schedule, Office Hours, Requirements, Fees, Priests, Directions, or how to Book an Appointment. What would you like to know?</div>
    </div>
    <form class="chat-input" id="chatForm">
      <input type="text" id="chatInput" placeholder="Type your question..." autocomplete="off">
      <button type="submit">Send</button>
    </form>
  </div>
<?php endif; ?>
<script>window.PARISHHUB_BASE_URL = "<?= url('') ?>";</script>
<script src="<?= url('public/js/app.js') ?>"></script>
</body>
</html>
