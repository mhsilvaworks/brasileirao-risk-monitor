# Monitor de Risco Brasileirão (Série A)

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
