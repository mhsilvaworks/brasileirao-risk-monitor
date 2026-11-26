# Monitor de Risco Brasileirão (Série A)

## Build e execução isolada da imagem Docker

Para construir somente a imagem PHP 8.3 com Apache, sem iniciar o MySQL:

```bash
docker build -t brasileirao-risk-monitor:latest .
```

Para executar um container a partir da imagem:

```bash
docker run -d \
  --name brasileirao-risk-monitor \
  -p 8083:80 \
  brasileirao-risk-monitor:latest
```

### Imagem publicada no GitHub Container Registry (GHCR)

A imagem também é publicada automaticamente no GitHub Container Registry após a integração de alterações na branch `main`, desde que os testes da CI sejam aprovados.

Imagem:

```text
ghcr.io/mhsilvaworks/brasileirao-risk-monitor
```

Tags disponíveis:
- `latest`: versão mais recente publicada.
- `sha-8f4ee529080c8eb291674f4fe05ce276003e63e2`: imagem associada ao commit da publicação.

Para baixar a imagem:

```bash
docker pull ghcr.io/mhsilvaworks/brasileirao-risk-monitor:latest
```

Para executar um container utilizando a imagem publicada:

```bash
docker run -d \
  --name brasileirao-risk-monitor \
  -p 8083:80 \
  ghcr.io/mhsilvaworks/brasileirao-risk-monitor:latest
```

Digest verificado na publicação:

```text
sha256:52c1d7dd7c7c99292346e8e001705577011fc39cc873680e2d66540c94f2cf4e
```

Para executar um container a partir da imagem local:

```bash
docker run -d \
  --name brasileirao-risk-monitor \
  -p 8083:80 \
  brasileirao-risk-monitor:latest
```

Para verificar o estado do container:

```bash
docker ps
```

Para consultar os logs:

```bash
docker logs brasileirao-risk-monitor
```

Para parar e remover o container:

```bash
docker rm -f brasileirao-risk-monitor
```

**Observação:** a execução isolada disponibiliza apenas o serviço PHP/Apache. Para iniciar também o MySQL e configurar as variáveis de ambiente necessárias, utilize o Docker Compose, conforme as instruções desta seção.

## Ambiente local com Docker Compose

### Pré-requisitos

- Docker Engine
- Docker Compose v2

### 1. Configurar o ambiente

Copie o arquivo de exemplo:

```bash
cp .env.example .env
```

Edite o `.env` e configure as senhas do usuário da aplicação e do administrador do MySQL. Configure também o token da API football-data.org, quando disponível.

Não envie o arquivo `.env` ao Git.

### 2. Iniciar os serviços

```bash
docker compose up --build -d
```

O ambiente utiliza:

- PHP 8.3 com Apache, na porta 8082.
- MySQL 8.0, na porta 3309.
- Volume persistente `mysql_data`.

A aplicação aguarda o MySQL ficar saudável antes de iniciar.

### 3. Verificar os serviços

```bash
docker compose ps
```

Consultar os logs:

```bash
docker compose logs -f
```

### 4. Banco de dados

Na primeira inicialização de um volume vazio, o MySQL executa automaticamente `database/schema.sql`.

Consultar as tabelas:

```bash
docker compose exec db sh -c \
  'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" -e "SHOW TABLES;"'
```

O volume `mysql_data` preserva os dados entre reinicializações.

O arquivo `database/seed.sql` não contém dados esportivos fixos. A fonte desses dados é a API football-data.org.

### 5. Parar os serviços

```bash
docker compose down
```

Esse comando preserva o volume.

Atenção: `docker compose down -v` remove o volume e apaga os dados persistidos.

### 6. Estado da implementação

A infraestrutura Docker e a estrutura do banco estão preparadas.

A implementação da classe de conexão PDO pertence à Issue #3. Os endpoints e o fluxo de sincronização dependem das respectivas implementações do projeto.
