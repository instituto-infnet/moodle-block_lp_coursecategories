# Bloco de planos de aprendizado por categoria de curso

Bloco do Moodle que lista os planos de aprendizado do usuário, agrupados por
categoria do curso associado às competências dos planos.

## Instalação ou atualização

1. Criar a pasta `blocks/lp_coursecategories` dentro da estrutura do Moodle, se
não houver.
2. Copiar todos os arquivos do plugin para dentro desta pasta.
3. Acessar a página principal de administração do Moodle.
4. Conferir o plugin na lista exibida e confirmar a atualização do banco de
dados do Moodle.

## Uso

Na página inicial do usuário, clicar na caixa de seleção "Adicionar um bloco" e
selecionar a opção "Planos de aprendizado por bloco".

## Adicionar auto ND para plágio

Ao marcar a rubrica de plágio, todas as rubricas devem ser marcadas como ND. No moodle vá em Administração do site > Aparência > Código HTML Adicional, no input "Dentro da tag HEAD" additionalhtmlhead inserir o script abaixo: 

const rubricText = 'O aluno cometeu plágio';
const currentURL = window.location.href;
const path = new URL(currentURL).pathname;
const urlParams = new URLSearchParams(currentURL);
const desiredPart = path.substring(path.indexOf('/', 1));
const isDesiredURL = desiredPart.endsWith('/assign/view.php');
document.addEventListener('DOMContentLoaded', function() {
    // Primeiro filtro - verifica se a URL termina com '/assign/view.php' 
    // e se tem um parâmetro chamado action com o valor grader que fica presente nas páginas de correção dos TPs e ATs
    if (isDesiredURL && urlParams.has('action') && urlParams.get('action') === 'grader') {

        const assignmentInfoDiv = document.querySelector('div[data-region="assignment-info"]');
        const tarefaAssessmentLink = Array.from(assignmentInfoDiv.querySelectorAll('a')).find((a) => a.textContent.includes("Tarefa: Assessment"));
        console.log("URL: 1- ", tarefaAssessmentLink);
        // true = Url correta e é o AT
        if (tarefaAssessmentLink) {                        
            observeDOMChanges();
        }
    }    
});

function observeDOMChanges() {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
        if (mutation.target.querySelectorAll(".criterion").length > 0) {
            observer.disconnect();
            checkClick();
        }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
}
  
function checkClick(){
    let trNodes = document.querySelectorAll(".criterion");   
    if(trNodes[0].textContent.includes(rubricText)){
        let tdNodes = trNodes[0].querySelectorAll('td[role="radio"]');
        tdNodes[0].onclick = function() {
            checkAllAsND(trNodes);
        };
    }
}

function checkAllAsND(trNodes){
    for (let i = 1; i < trNodes.length; i++) {
        let currentItem = trNodes[i];
        let radioLeves = currentItem.querySelectorAll('td[role="radio"]');
        if (radioLeves[0].getAttribute('aria-checked') !== 'true') {
            radioLeves[0].click();
        }
    }
}