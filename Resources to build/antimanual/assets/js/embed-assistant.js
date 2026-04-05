document.addEventListener('DOMContentLoaded', function () {
    var chatToggle = document.querySelector('.antimanual-embeded-chat-toggle');
    chatToggle.addEventListener('click', function (e) {
        e.preventDefault();
        // Toggle chat icon visibility
        if (chatToggle) {
            var chatIcon = chatToggle.querySelector('.help-icon');
            var hideIcon = chatToggle.querySelector('.close-icon');
            if (chatIcon) chatIcon.style.display = (chatIcon.style.display === 'none' ? 'block' : 'none');
            if (hideIcon) hideIcon.style.display = (hideIcon.style.display === 'none' ? 'block' : 'none');
        }

        // Toggle show-chatbox class
        if (chatToggle) chatToggle.classList.toggle('show-chatbox');

        // Toggle iframe visibility
        var chatboxWrapper = document.querySelector('.antimanual-chatbox-iframe-wraper');
        if (chatboxWrapper) chatboxWrapper.classList.toggle('show-chatbox');
    });

});
