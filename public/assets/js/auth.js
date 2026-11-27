// Seleciona todos os botões responsáveis por abrir janelas modais.
const botoesAbrir = document.querySelectorAll('[data-modal]');

// Adiciona um evento de clique a cada botão.
botoesAbrir.forEach(function (botao) {

    botao.addEventListener('click', function () {

        // Identifica qual janela deve ser aberta.
        const idModal = botao.dataset.modal;

        // Localiza a janela pelo seu identificador.
        const modal = document.getElementById(idModal);

        // Abre a janela caso ela exista.
        if (modal && !modal.open) {
            modal.showModal();
        }

    });

});

// Seleciona todos os botões responsáveis por fechar janelas.
const botoesFechar = document.querySelectorAll('[data-fechar-modal]');

botoesFechar.forEach(function (botao) {

    botao.addEventListener('click', function () {

        // Localiza a janela que contém o botão.
        const modal = botao.closest('dialog');

        if (modal) {
            modal.close();
        }

    });

});

// Limpa os campos de senha quando uma janela é fechada.
const modais = document.querySelectorAll('dialog');

modais.forEach(function (modal) {

    modal.addEventListener('close', function () {

        const senhas = modal.querySelectorAll('input[type="password"]');

        senhas.forEach(function (campo) {
            campo.value = '';
        });

        // Remove a confirmação de exclusão, caso exista.
        const confirmacao = modal.querySelector(
            '[name="confirmar_exclusao"]'
        );

        if (confirmacao) {
            confirmacao.checked = false;
        }

    });

});
