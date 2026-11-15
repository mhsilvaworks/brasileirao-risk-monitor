#  Guia de Versionamento e Colaboração — Monitor de Risco Brasileirão

Este guia define como a equipe versiona o código no repositório `brasileirao-risk-monitor`. O objetivo é garantir um histórico limpo, evitar conflitos de merge e gerar evidências claras de colaboração via Pull Requests para a avaliação da disciplina.

---

## 1. Visão Geral e Divisão de Papéis

| Integrante | Papel | Branch Principal | Pastas de Responsabilidade |
| :--- | :--- | :--- | :--- |
| **Pessoa 1** | Back-end & Banco de Dados | `feat/api-sync-db` | `/database/`, `/src/Database.php`, `/src/StandingsService.php`, `/src/SyncService.php` |
| **Pessoa 2** | Motor Estatístico & Front-end | `feat/simulador-ui` | `/public/index.php`, `/public/assets/app.js`, `/public/assets/style.css` |
| **Pessoa 3** | DevOps, QA & Admin Repo | `feat/devops-ci-cd` | `/.github/`, `/tests/`, `Dockerfile`, `docker-compose.yml`, `README.md` |

---

## 2. ⚙️ Configuração Obrigatória Após Clonar (Fazer 1 única vez)

Assim que clonar o repositório, cada integrante deve rodar os comandos abaixo no terminal **antes do primeiro commit**:

```bash
git clone https://github.com/mhsilvaworks/brasileirao-risk-monitor.git
cd brasileirao-risk-monitor

# 1. Ativa o validador automático de mensagens de commit
git config core.hooksPath .github/hooks
chmod +x .github/hooks/commit-msg

# 2. Cria o arquivo de variáveis locais (nunca vai pro Git)
cp .env.example .env
```

> **Atenção:** Peça a chave real da API `football-data.org` no canal privado da equipe e cole dentro do seu arquivo `.env` local.

---

## 3. Estrutura de Branches (GitHub Flow Adaptado)

Para manter agilidade dentro do prazo de 12 dias, utilizamos a branch `main` protegida e 3 branches de frentes de trabalho:

| Branch | Finalidade | Push Direto | Como integra na `main` |
| :--- | :--- | :--- | :--- |
| **`main`** | Código estável, testado e pronto para entrega | ❌ **Bloqueado** | Apenas via **Pull Request aprovado** |
| **`feat/api-sync-db`** | Desenvolvimento do Banco e API REST | ✅ Pessoa 1 | Abre PR para `main` |
| **`feat/simulador-ui`** | Desenvolvimento da UI e Monte Carlo JS | ✅ Pessoa 2 | Abre PR para `main` |
| **`feat/devops-ci-cd`** | Pipeline, Testes PHPUnit, Docker e Docs | ✅ Pessoa 3 | Abre PR para `main` |

> ⚠️ **Regra de Ouro:** Nunca tente dar `git push origin main`. O GitHub recusará o envio. Trabalhe sempre na sua branch (`feat/...`) e integre via Pull Request.

---

## 4. Passo a Passo do Dia a Dia

### Passo A: Antes de começar a codificar (Sincronizar)
Sempre puxe as novidades que os outros integrantes já aprovaram na `main` para evitar conflitos:

```bash
git checkout sua-branch        # ex: git checkout feat/api-sync-db
git pull origin main           # traz atualizações aprovadas da main
```

### Passo B: Durante o desenvolvimento (Salvar e Enviar)
Faça commits pequenos, atômicos e frequentes na sua própria branch:

```bash
git status                     # confira se o .env NÃO aparece na lista!
git add .
git commit -m "feat: adiciona endpoint de classificacao da serie a"
git push origin sua-branch
```

### Passo C: Entregar uma funcionalidade (Abrir Pull Request)
Quando concluir uma entrega (ex: `schema.sql` finalizado ou tela de simulação funcionando):

1. Acesse o repositório no GitHub e clique em **Pull Requests → New pull request**.
2. Selecione **base: `main`** ← **compare: `sua-branch`**.
3. Preencha o checklist que aparecerá automaticamente na descrição.
4. Marque os outros 2 colegas em **Reviewers** no menu lateral direito.
5. Assim que **pelo menos 1 colega aprovar** e os checks do GitHub Actions passarem (✅), clique em **Merge pull request**.

---

## 5. Padrão de Commits (Conventional Commits)

O hook configurado no Passo 2 bloqueia automaticamente commits fora deste padrão:

**Formato:** `tipo: verbo no presente + descrição curta` *(tudo minúsculo, sem ponto final, máximo de 72 caracteres)*.

* `feat:` nova funcionalidade (ex: `feat: implementa calculo de desempate da cbf`)
* `fix:` correção de bug (ex: `fix: corrige contagem de jogos adiados`)
* `test:` testes automatizados (ex: `test: adiciona caso de teste para punicao do stjd`)
* `ci:` pipeline e Docker (ex: `ci: configura build e push da imagem no ghcr`)
* `docs:` documentação (ex: `docs: atualiza instrucoes de deploy no readme`)
* `chore:` tarefas de configuração (ex: `chore: ajusta dependencias do composer`)

---

## 6. 🆘 Resolução Rápida de Problemas no Git

* **Errei a mensagem do último commit (antes do push):**
  ```bash
  git commit --amend -m "feat: mensagem corrigida"
  ```

* **Comitei o arquivo `.env` com a chave real sem querer (antes do push):**
  ```bash
  git reset HEAD~1
  # Garanta que o .env está listado no .gitignore e refaça o commit sem ele
  ```

* **Deu conflito de merge ao abrir o Pull Request:**
  ```bash
  git checkout sua-branch
  git pull origin main
  # Abra o editor, resolva as marcações <<<<<<< e >>>>>>>, salve e rode:
  git add .
  git commit -m "fix: resolve conflitos de merge com a main"
  git push origin sua-branch
  ```

---

## 7. 🔒 Segurança e Variáveis de Ambiente

* **Tolerância zero** para senhas, tokens ou arquivos `.env` reais dentro do repositório.
* Toda nova variável de ambiente deve ser adicionada primeiro no `.env.example` (apenas o nome da chave, sem valor real) e comunicada ao responsável DevOps para cadastro nos **GitHub Secrets**.