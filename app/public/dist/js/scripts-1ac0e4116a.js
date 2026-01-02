const mastodon = document.getElementById("form_mastodon");
const at = document.getElementById("form_at");
const networks = document.getElementById("networks");

const network_back = document.querySelectorAll(".network_back");
network_back.forEach(function(e) {
    e.addEventListener("click", function() {
        if (mastodon) mastodon.style.display = 'none';
        if (at) at.style.display = 'none';
        if (networks) networks.style.display = 'block';
    });
});

const network_mastodon = document.getElementById("network_mastodon");
if (network_mastodon) {
    network_mastodon.addEventListener('click', function(e) {
        e.preventDefault();

        if (mastodon) mastodon.style.display = 'block';
        if (at) at.style.display = 'none';
        if (networks) networks.style.display = 'none';
    });
}

const network_at = document.getElementById("network_at");
if (network_at) {
    network_at.addEventListener('click', function(e) {
        e.preventDefault();

        if (mastodon) mastodon.style.display = 'none';
        if (at) at.style.display = 'block';
        if (networks) networks.style.display = 'none';
    });
}

const login_mastodon = document.getElementById("login_mastodon");
const submit_mastodon = document.getElementById("submit_mastodon");
if (submit_mastodon) {
    submit_mastodon.addEventListener('click', function(e) {
        e.preventDefault();

        let instance_mastodon = document.getElementById("instance_mastodon");
        if (instance_mastodon && instance_mastodon.value.trim() != '') {
            if (login_mastodon) login_mastodon.classList.add('login--waiting');
            instance_mastodon.setAttribute('readonly', '');
            submit_mastodon.setAttribute('disabled', '');

            if (login_mastodon) login_mastodon.submit();
        }
    });
}

const login_at = document.getElementById("login_at");
const submit_at = document.getElementById("submit_at");
if (submit_at) {
    submit_at.addEventListener('click', function(e) {
        e.preventDefault();

        let username_at = document.getElementById('username_at');
        let password_at = document.getElementById('password_at');
        if (username_at && password_at && username_at.value.trim() != '' && password_at.value.trim() != '') {
            if (login_at) login_at.classList.add('login--waiting');
            username_at.setAttribute('readonly', '');
            password_at.setAttribute('readonly', '');
            submit_at.setAttribute('disabled', '');

            if (login_at) login_at.submit();
        }
    });
}

const edit = document.querySelector(".edit");
if (edit) {
    const edit_button = document.getElementById("edit");
    const save = document.querySelector(".save");
    edit_button.addEventListener("click", function() {
        edit.style.display = "none";
        save.style.display = "flex";
    });
}

const save_form = document.getElementById('save');
const save_button = document.getElementById('save_button');
if (save_button) {
    save_button.addEventListener('click', function(e) {
        e.preventDefault();
        let lastfm_username = document.getElementById('lastfm_username');
        let hour = document.getElementById('hour');
        let day_of_week = document.getElementById('day_of_week');
        let timezone = document.getElementById('timezone');

        if (lastfm_username.value.trim() != '' && hour.value.trim() != '') {
            save_form.classList.add('save--waiting');
            lastfm_username.setAttribute('readonly', '');
            hour.setAttribute('readonly', '');
            save_button.setAttribute('disabled', '');

            save_form.submit();
        }
    });
}

const delete_button = document.getElementById("delete_button");
if (delete_button) {
    delete_button.addEventListener("click", function(e) {
        e.preventDefault();
        const confirmMessage = delete_button.getAttribute('data-confirm') || 'Are you sure?';
        if (confirm(confirmMessage)) {
            document.getElementById('delete_account').submit();
        }
    });
}