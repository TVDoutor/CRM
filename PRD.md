# PRD — TV Doutor CRM
## Product Requirements Document

**Produto:** TV Doutor CRM — Controle de Equipamentos  
**Versão do documento:** 1.0  
**Data:** 27/02/2026  
**Responsável:** TV Doutor  
**URL de produção:** https://crm.tvdoutor.com.br  
**Stack:** PHP 8.3 · MySQL 5.7 · Tailwind CSS · PDO · HostGator

---

## 1. Visão Geral do Produto

O **TV Doutor CRM** é um sistema web de gestão de equipamentos de exibição digital (players, TVs, monitores) alocados a clientes. Ele centraliza o ciclo de vida completo de cada equipamento — da entrada no estoque até a devolução ou baixa — e sincroniza automaticamente dados de clientes e projetos com o Pipedrive, CRM externo já utilizado pela equipe.

### Problema resolvido
A TV Doutor gerencia centenas de equipamentos distribuídos em clientes ("pontos de exibição"). Sem um sistema próprio, o controle era feito de forma fragmentada entre planilhas e o Pipedrive, sem visibilidade do status real de cada equipamento, datas de garantia, histórico de movimentações e responsabilidades por cliente.

### Proposta de valor
- Rastreabilidade total de cada equipamento, do recebimento à baixa
- Integração bidirecional com Pipedrive para eliminar retrabalho de cadastro
- Kanban visual alinhado com o processo operacional real da empresa
- Alertas automáticos de garantia (12 meses a partir da data de compra)
- Controle de acesso por perfil para segurança operacional

---

## 2. Usuários e Perfis de Acesso

| Perfil | Label | Descrição |
|---|---|---|
| `admin` | Administrador | Acesso total ao sistema, incluindo usuários, auditoria e exclusão de registros |
| `manager` | Gerente | Acesso operacional completo, exceto gestão de usuários e auditoria por usuário |
| `user` | Usuário | Acesso operacional básico: movimentações, consultas e relatórios gerais |

### Matriz de permissões por módulo

| Módulo / Funcionalidade | Usuário | Gerente | Admin |
|---|---|---|---|
| Dashboard | Sim | Sim | Sim |
| Kanban | Sim | Sim | Sim |
| Saída de Equipamento | Sim | Sim | Sim |
| Devolução / Retorno | Sim | Sim | Sim |
| Entrada de Lote | Sim | Sim | Sim |
| Histórico de Operações | Sim | Sim | Sim |
| Equipamentos (visualizar) | Sim | Sim | Sim |
| Equipamentos (criar/editar) | Não | Sim | Sim |
| Equipamentos (excluir) | Não | Não | Sim |
| Clientes (visualizar) | Sim | Sim | Sim |
| Clientes (criar/editar) | Não | Sim | Sim |
| Relatórios (todos exceto Auditoria) | Sim | Sim | Sim |
| Auditoria por Usuário | Não | Não | Sim |
| Integrações Pipedrive | Não | Sim | Sim |
| Usuários (listar/criar/editar/excluir) | Não | Não | Sim |
| Perfil próprio (nome/telefone/senha) | Sim | Sim | Sim |

---

## 3. Arquitetura do Sistema

```
crm.tvdoutor.com.br/
├── index.php                  → Gateway (redirect login ou dashboard)
├── config/
│   ├── database.php           → Conexão PDO singleton + BASE_URL dinâmica
│   ├── pipedrive.php          → Token, constantes, mapeamentos e funções da API Pipedrive
│   └── migrate_projects.sql   → DDL das tabelas de projetos Pipedrive
├── includes/
│   ├── auth.php               → Sessão, autenticação, CSRF, controle de roles
│   ├── helpers.php            → Funções utilitárias globais (warranty, badges, audit, kanban)
│   └── navbar.php             → Sidebar responsiva com visibilidade por role
└── pages/
    ├── login.php / logout.php
    ├── dashboard.php
    ├── kanban.php
    ├── equipment/             → CRUD + entrada em lote
    ├── clients/               → CRUD de clientes
    ├── operations/            → Saída, retorno, histórico
    ├── pipedrive/             → Painéis de integração
    ├── reports/               → 5 relatórios
    ├── users/                 → Gestão de usuários (admin only)
    ├── profile/               → Perfil do usuário logado
    └── api/                   → Endpoints JSON internos e scripts de sync Pipedrive
```

**43 arquivos PHP** · Sem framework · Sem dependências npm · Deploy via FTP/cPanel

---

## 4. Banco de Dados

### 4.1 Diagrama de entidades (simplificado)

```
users
  └─ cria/movimenta ──→ equipment
  └─ registra ──→ audit_log
  └─ registra ──→ kanban_history

equipment ──→ equipment_models  (modelo/marca)
equipment ──→ clients           (cliente atual)
equipment ──→ equipment_notes   (notas internas)
equipment ──→ kanban_history    (linha do tempo de status)
equipment ──→ equipment_operation_items ──→ equipment_operations ──→ clients

clients ──→ pipedrive_projects  (projetos vinculados)

pipedrive_sync_log              (log de sync de clientes)
pipedrive_projects_sync_log     (log de sync de projetos)
```

### 4.2 Tabelas e colunas principais

#### `users`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| name | VARCHAR(150) | |
| email | VARCHAR(200) UNIQUE | |
| password_hash | VARCHAR(255) | bcrypt |
| role | ENUM(admin, manager, user) | |
| phone | VARCHAR(30) | nullable |
| is_active | TINYINT(1) | DEFAULT 1 |
| last_login | DATETIME | Atualizado no login |
| created_at, updated_at | DATETIME | |

#### `clients`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| client_code | VARCHAR(20) UNIQUE | Ex: P3386 (prefixo P + número) |
| name | VARCHAR(200) | |
| contact_name | VARCHAR(200) | Razão Social |
| cnpj | VARCHAR(20) | nullable |
| phone | VARCHAR(30) | nullable |
| email | VARCHAR(200) | nullable |
| address, city, state | VARCHAR/CHAR | Endereço completo |
| notes | TEXT | nullable |
| is_active | TINYINT(1) | DEFAULT 1 |
| pipedrive_org_id | INT | FK lógica para Pipedrive |
| pipedrive_person_id | INT | nullable |
| pipedrive_synced_at | DATETIME | nullable |

#### `equipment_models`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| brand | VARCHAR(100) | Ex: Amlogic, AQUARIO |
| model_name | VARCHAR(100) | Ex: K95W, STV-2000 |
| category | VARCHAR(100) | nullable |
| is_active | TINYINT(1) | DEFAULT 1 |

**Modelos cadastrados (originados do Pipedrive):**
Amlogic K95W · Amlogic K3 PRO · AQUARIO STV-2000 · AQUARIO STV-3000 · Computador · Smart TV · Monitor LG · Proeletronic PROSB3000 · Proeletronic PROSB5000 · Outros

#### `equipment`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| asset_tag | VARCHAR(50) UNIQUE | Etiqueta física (ex: SNPRO24DEC019450) |
| model_id | INT FK | → equipment_models |
| serial_number | VARCHAR(100) | nullable |
| mac_address | VARCHAR(20) | Formato AA:BB:CC:DD:EE:FF, nullable |
| condition_status | ENUM | novo / usado / bom / regular / ruim / sucateado |
| kanban_status | ENUM | 9 valores (ver seção 5) |
| contract_type | ENUM | comodato / equipamento_cliente / parceria |
| current_client_id | INT FK | → clients, nullable |
| entry_date | DATE | Obrigatória |
| purchase_date | DATE | Obrigatória (base para garantia de 12 meses) |
| batch | VARCHAR(50) | Lote, nullable |
| notes | TEXT | nullable |
| created_by | INT FK | → users |
| updated_by | INT FK | → users, nullable |

#### `kanban_history`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| equipment_id | INT FK | → equipment |
| from_status | VARCHAR | NULL no registro inicial |
| to_status | VARCHAR | |
| client_id | INT FK | → clients, nullable |
| moved_by | INT FK | → users |
| notes | TEXT | nullable |
| moved_at | DATETIME | DEFAULT CURRENT_TIMESTAMP |

#### `equipment_operations`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| operation_type | ENUM | ENTRADA / SAIDA / RETORNO |
| operation_date | DATETIME | |
| client_id | INT FK | → clients, nullable |
| notes | TEXT | nullable |
| performed_by | INT FK | → users |

#### `equipment_operation_items`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| operation_id | INT FK | → equipment_operations |
| equipment_id | INT FK | → equipment |
| accessories_power | TINYINT(1) | Fonte devolvida? |
| accessories_hdmi | TINYINT(1) | Cabo HDMI devolvido? |
| accessories_remote | TINYINT(1) | Controle devolvido? |
| condition_after_return | ENUM | ok / manutencao / descartar |
| return_notes | TEXT | nullable |

#### `equipment_notes`
| Coluna | Tipo |
|---|---|
| id | INT PK |
| equipment_id | INT FK |
| user_id | INT FK |
| note | TEXT |
| created_at | DATETIME |

#### `audit_log`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| user_id | INT FK | → users |
| action | VARCHAR | CREATE / UPDATE / DELETE / SAIDA / RETORNO / KANBAN_MOVE / NOTE / PIPEDRIVE_SYNC / … |
| entity_type | VARCHAR | equipment / client / user / integration |
| entity_id | INT | nullable |
| old_value | JSON | nullable |
| new_value | JSON | nullable |
| description | TEXT | nullable |
| ip_address | VARCHAR | nullable |
| created_at | DATETIME | |

#### `pipedrive_projects`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| pipedrive_id | INT UNIQUE | ID do projeto no Pipedrive |
| title | VARCHAR(255) | Título completo (ex: "P3209 - Cliente | 597C") |
| client_code | VARCHAR(50) | Extraído do título (regex `^P[\d.]+`) |
| asset_tag | VARCHAR(20) | Extraído do título (regex 4 chars hex após `\|`) |
| client_id | INT FK | → clients, nullable |
| pipedrive_org_id | INT | |
| board_id | INT | 8 = Pontos de Exibição |
| phase_id | INT | |
| phase_name | VARCHAR(100) | |
| status | ENUM | open / completed / canceled / deleted |
| start_date, end_date | DATE | nullable |
| description | TEXT | nullable |
| pipedrive_synced_at | DATETIME | |

---

## 5. Módulos do Sistema

### 5.1 Dashboard

**Objetivo:** Visão geral instantânea do estoque e das últimas movimentações.

**KPIs exibidos:**
- Em Estoque (equipamentos com status: entrada, aguardando_instalacao, equipamento_usado)
- Alocados (status: alocado)
- Em Manutenção
- Total Geral
- Estoque por modelo: novos vs usados

**Seções:**
- Tabela de estoque por modelo (quantidade nova + usada)
- Últimas 10 movimentações (data, tipo, cliente, etiquetas, responsável)
- Alerta: equipamentos em manutenção há mais de 30 dias

---

### 5.2 Kanban

**Objetivo:** Visão operacional do status de todos os equipamentos em formato de quadro Kanban, alinhado com o processo real da empresa e integrado ao Pipedrive.

**9 colunas (status interno → label exibido):**

| Status interno | Label | Cor |
|---|---|---|
| `entrada` | Entrada | Cinza |
| `aguardando_instalacao` | Aguardando Instalação | Amarelo |
| `alocado` | Alocado | Verde |
| `manutencao` | Manutenção | Laranja |
| `licenca_removida` | Licença Removida | Vermelho |
| `equipamento_usado` | Equipamento Usado | Azul |
| `comercial` | Comercial | Roxo |
| `processo_devolucao` | Processo Devolução | Rosa |
| `baixado` | Baixado | Preto |

**Mapeamento Pipedrive → Kanban:**

| Fase no Pipedrive (Board: Pontos de Exibição) | Status no CRM |
|---|---|
| Entrada | `entrada` |
| Pontos Ativos Onsign | `alocado` |
| Offline +31 Dias | `licenca_removida` |
| Encaminhado ao CS - Sem Licença | `aguardando_instalacao` |
| Processo de Cancelamento | `processo_devolucao` |

**Funcionalidades:**
- Drag-and-drop com confirmação modal e campo de observação
- Busca em tempo real por etiqueta, modelo ou cliente
- Badge de fase Pipedrive em cada card (quando sincronizado)
- Badge de garantia em cards com vencimento crítico (vencendo ou vencida)
- Vista Board (desktop) e Lista/Accordion (mobile)
- Link direto ao projeto no Pipedrive em cada card

**API interna:** `POST /api/kanban_move.php` (JSON + CSRF)

---

### 5.3 Equipamentos

**Objetivo:** Cadastro, consulta e gestão completa do ciclo de vida de cada equipamento.

#### 5.3.1 Listagem (`/equipment/index.php`)
- Tabela paginada (30 por página)
- Filtros: busca livre (etiqueta/SN/MAC), status Kanban, condição, tipo de contrato, modelo, lote
- Colunas: Etiqueta, Modelo, S/N, Condição, Status Kanban, Garantia, Tipo, Cliente, Lote, Ações
- **Modal automático de alertas de garantia** ao entrar na página (se houver equipamentos críticos)
- Ações por linha: Ver (todos) · Editar (admin/manager)
- Botões de cabeçalho: + Novo (admin/manager) · Entrada de Lote (todos)

#### 5.3.2 Cadastro individual (`/equipment/create.php`)
**Acesso:** admin, manager

**Campos obrigatórios:** asset_tag, model_id, entry_date, purchase_date, contract_type
**Campos opcionais:** serial_number, mac_address, batch, notes
**Campo de status:** kanban_status inicial (entrada ou aguardando_instalacao)

**Fluxo pós-cadastro:**
1. INSERT em `equipment`
2. INSERT em `kanban_history` (from=NULL, to='entrada')
3. INSERT em `audit_log`
4. Redirect para ficha do equipamento

#### 5.3.3 Edição (`/equipment/edit.php`)
**Acesso:** admin, manager (exclusão somente admin)

Mesmos campos do cadastro. Exclusão com modal de confirmação por digitação do asset_tag. Antes de deletar: remove kanban_history, equipment_notes, audit_log e registros de pipedrive_projects vinculados.

#### 5.3.4 Ficha completa (`/equipment/view.php`)
**Acesso:** todos

**Seções:**
- Header: asset_tag, modelo, badges de condição / status kanban / contrato / garantia
- Dados técnicos: S/N, MAC, data de entrada, lote, data de compra, vencimento da garantia
- Cliente atual com link para ficha do cliente
- Linha do Tempo: histórico completo de mudanças de status com data, responsável e observação
- Devoluções: checklist de periféricos (fonte, HDMI, controle) e condição registrada
- Notas internas: adicionar via AJAX (`POST /api/add_note.php`)
- Botão Editar (admin/manager)

#### 5.3.5 Entrada em lote (`/equipment/batch_entry.php`)
**Acesso:** todos

Cadastra múltiplos equipamentos em uma única operação atômica (transação SQL). Campos comuns ao lote: batch, entry_date, purchase_date, model_id. Por equipamento: asset_tag, serial_number, mac_address. Geração dinâmica de linhas via JS. Validação de duplicatas intra-lote e contra o banco.

---

### 5.4 Clientes

**Objetivo:** Cadastro e consulta dos clientes ("pontos de exibição") que recebem os equipamentos.

#### Listagem
- Filtros: busca (nome/código/CNPJ), status ativo/inativo
- Colunas: Código, Nome, CNPJ, Telefone, Cidade/UF, Equipamentos Ativos, Status

#### Ficha do cliente (`/clients/view.php`)
- Dados cadastrais completos
- Equipamentos ativos (com dias alocados)
- Projetos Pipedrive vinculados (com link direto)
- Histórico completo de todos os equipamentos já alocados

#### Cadastro/Edição
**Acesso:** admin, manager

**Campos:** client_code *, name *, contact_name, cnpj, phone, email, address, city, state, notes, is_active

> **Nota:** O `client_code` segue o padrão Pipedrive (ex: `P3386`). Usado para vincular projetos do Pipedrive automaticamente.

---

### 5.5 Movimentações

#### 5.5.1 Saída de Equipamento (`/operations/saida.php`)
**Acesso:** todos

Registra alocação de equipamentos para um cliente.

**Pré-condição:** Equipamento com status entrada, aguardando_instalacao ou equipamento_usado.

**Campos:**
- Cliente (autocomplete por nome ou código)
- Tipo de contrato (comodato / equipamento_cliente / parceria)
- Data e hora da operação
- Observações gerais
- Seleção de equipamentos (checkboxes filtráveis por etiqueta/modelo)

**Recurso:** Modal de cadastro rápido de novo cliente via AJAX (`POST /api/save_client.php`)

**Resultado:** Equipamentos marcados como `alocado`, vinculados ao cliente.

#### 5.5.2 Devolução / Retorno (`/operations/retorno.php`)
**Acesso:** todos

Registra retorno de equipamentos alocados.

**Pré-condição:** Equipamento com status `alocado`.

**Por equipamento devolvido:**
- Periféricos devolvidos (fonte, HDMI, controle) — checkboxes
- Condição após retorno:
  - `ok` → equipamento vai para `equipamento_usado`
  - `manutencao` → vai para `manutencao`
  - `descartar` → vai para `baixado`
- Observações do equipamento

**Agrupamento:** Equipamentos exibidos por cliente para facilitar a operação.

#### 5.5.3 Histórico de Operações (`/operations/history.php`)
**Acesso:** todos

Histórico paginado de todas as operações.

**Filtros:** período (data), tipo (ENTRADA/SAIDA/RETORNO/KANBAN_MOVE/etc), usuário responsável.

**Colunas:** Data, Tipo, Cliente, Qtd, Etiquetas, Responsável, Observações.

---

### 5.6 Relatórios

**Acesso geral:** todos os usuários logados (exceto Auditoria por Usuário: somente admin)

| Relatório | Arquivo | Descrição |
|---|---|---|
| Estoque Atual | `stock.php` | Equipamentos disponíveis (entrada/aguardando/usado). Filtros: condição, modelo, lote. Subtotais por modelo (novo/usado). |
| Entradas e Saídas | `entries_exits.php` | Operações no período. Filtros: data de/até, tipo. KPIs por tipo + tabela detalhada. |
| Histórico Equipamento | `by_equipment.php` | Timeline completa de um equipamento (busca por etiqueta ou S/N). |
| Relatório por Cliente | `by_client.php` | Equipamentos ativos e histórico completo de um cliente. Busca por código ou nome. |
| Auditoria por Usuário | `by_user.php` | Todas as ações de um usuário no sistema. Filtros: usuário, período, tipo de ação. Exibe IP, entidade, old/new values. **Acesso: somente admin.** |

---

### 5.7 Integração Pipedrive

**Objetivo:** Sincronizar dados de clientes e projetos do Pipedrive automaticamente, eliminando duplicidade de cadastro e mantendo o Kanban atualizado.

#### 5.7.1 Sincronização de Clientes (`/pipedrive/index.php`)
**Acesso:** admin, manager

**Origem:** Organizações do filtro #1159 do Pipedrive (`/organizations/list/filter/1159`)

**Dados sincronizados:**

| Campo Pipedrive | Campo CRM |
|---|---|
| Código do Cliente (campo customizado) | client_code |
| name | name |
| Razão Social (campo customizado) | contact_name |
| CNPJ (campo customizado) | cnpj |
| E-mail principal (campo customizado) | email |
| Telefone (campo customizado) | phone |
| Estado (campo customizado) | state |

**Automação:** Cron job a cada 6 horas
```
0 */6 * * * /usr/local/bin/php /home2/tvdout68/crm.tvdoutor.com.br/pages/api/pipedrive_sync.php cron
```

#### 5.7.2 Sincronização de Projetos e Kanban (`/pipedrive/projects.php`)
**Acesso:** admin, manager

**Origem:** Board "Pontos de Exibição" (ID 8) do Pipedrive

**Formato do título do projeto:** `P3209 - Nome do Cliente | 597C`
- `P3209` = client_code do cliente no CRM
- `597C` = asset_tag (4 chars hexadecimais) do equipamento

**3 operações independentes (botões separados):**

| Operação | Endpoint | O que faz |
|---|---|---|
| Sincronizar Projetos | `pipedrive_projects_sync.php` | Atualiza `pipedrive_projects` + atualiza `kanban_status` dos equipamentos baseado na fase atual do projeto |
| Importar Equipamentos | `pipedrive_import_equipment.php` | Cria novos equipamentos no CRM a partir de projetos que ainda não têm correspondência |
| Atualizar Dados | `pipedrive_update_equipment.php` | Atualiza modelo, MAC, lote e data de compra nos equipamentos já importados |

---

### 5.8 Usuários

**Acesso:** somente admin

**Funcionalidades:**
- Listar todos os usuários (nome, e-mail, perfil, telefone, último login, status)
- Criar novo usuário (nome *, e-mail *, telefone, perfil, senha *)
- Editar usuário (todos os campos, inclusive desativar conta)
- Alterar senha de qualquer usuário
- Excluir usuário (modal de confirmação por e-mail) — não permite excluir a si mesmo
- Proteção: não é possível rebaixar o único administrador do sistema

---

### 5.9 Perfil do Usuário

**Acesso:** qualquer usuário logado (somente sobre si mesmo)

**Dois formulários:**
1. **Dados do perfil:** nome, telefone (e-mail: readonly)
2. **Alteração de senha:** senha atual + nova senha + confirmação

> E-mail só pode ser alterado por um administrador via `/users/edit.php`.

---

### 5.10 Sistema de Garantia

**Regra de negócio:** Todo equipamento tem garantia de 12 meses a partir da `purchase_date`.

**Estados possíveis:**

| Status | Condição | Badge | Cor |
|---|---|---|---|
| `ok` | Mais de 30 dias até o vencimento | Garantia | Verde |
| `vencendo` | 0–30 dias até o vencimento | Vencendo | Laranja |
| `vencida` | Já passou a data de vencimento | Vencida | Vermelho |
| `sem_data` | `purchase_date` não preenchida | — Sem data | Cinza |

**Presença no sistema:**
- Badge na listagem de equipamentos
- Badge na ficha do equipamento (`view.php`)
- Badge nos cards do Kanban (apenas vencendo/vencida)
- **Modal automático** na listagem ao entrar na página (se houver equipamentos críticos)

---

## 6. APIs Internas (JSON)

| Endpoint | Método | Acesso | Descrição |
|---|---|---|---|
| `/api/kanban_move.php` | POST JSON | Todos (logados) | Move equipamento entre colunas do Kanban. Valida CSRF. Retorna `{success, from, to}` |
| `/api/add_note.php` | POST JSON | Todos (logados) | Adiciona nota interna a um equipamento. Retorna `{success, id}` |
| `/api/save_client.php` | POST JSON | Todos (logados) | Cadastro rápido de cliente (modal na saída de equipamento). Retorna `{success, id}` |
| `/api/get_equipment.php` | GET | Todos (logados) | Busca equipamento por ID ou texto. Retorna array JSON |
| `/api/pipedrive_sync.php` | POST/GET | admin, manager / cron | Sincroniza clientes do Pipedrive |
| `/api/pipedrive_projects_sync.php` | POST/GET | admin, manager / cron | Sincroniza projetos e atualiza Kanban |
| `/api/pipedrive_import_equipment.php` | POST/GET | admin, manager / cron | Importa novos equipamentos do Pipedrive |
| `/api/pipedrive_update_equipment.php` | POST/GET | admin, manager / cron | Atualiza dados de equipamentos existentes |

**Segurança:** Todos os endpoints POST requerem token CSRF válido na sessão. Endpoints de cron aceitam GET com `cron_key=tvd_cron_pip_2026`.

---

## 7. Segurança

| Mecanismo | Implementação |
|---|---|
| Autenticação | Sessão PHP com `session_regenerate_id(true)` no login |
| Senhas | Armazenadas com `password_hash(PASSWORD_BCRYPT)` |
| CSRF | Token de sessão validado em todo POST (helper `csrfValidate()`) |
| Autorização | `requireRole(array $roles)` em cada página/endpoint protegido |
| XSS | `htmlspecialchars()` em todos os outputs via helper `sanitize()` |
| SQL Injection | PDO com prepared statements em todas as queries |
| Auditoria | Toda ação significativa registrada em `audit_log` com IP, usuário, old/new values |
| Proteção de cron | Chave secreta `PIPEDRIVE_CRON_KEY` obrigatória para execução via URL |

---

## 8. UX e Responsividade

**Design system:** Tailwind CSS (CDN) com paleta customizada `brand` (#1B4F8C).

**Breakpoints:**
- Mobile: sidebar oculta (hamburguer + overlay), colunas da tabela ocultadas progressivamente
- Tablet (`md`): colunas secundárias aparecem
- Desktop (`lg`/`xl`): layout completo, sidebar fixa

**Navbar responsiva:**
- Desktop: sidebar fixa à esquerda, largura `w-64`
- Mobile: drawer lateral com `translate-x-full` → `translate-x-0`, overlay escuro, fechamento ao clicar fora

**Kanban:**
- Desktop: scroll horizontal entre colunas
- Mobile: accordion por status (lista)

**Padrões de UI:**
- Badges coloridos por status/condição/garantia/role
- Flash messages (sucesso/erro/info) automáticas entre páginas
- Modais de confirmação para ações destrutivas
- Tooltips em badges de garantia (data exata e dias restantes)

---

## 9. Infraestrutura e Deploy

| Item | Configuração |
|---|---|
| Servidor | HostGator (cPanel) |
| PHP | 8.3 (ea-php83) |
| MySQL | 5.7.23 |
| Host do banco | 108.167.132.55 |
| Database | tvdout68_crm |
| Domínio | crm.tvdoutor.com.br |
| Deploy | Upload via FTP/cPanel (arquivo ZIP) |
| Cron | cPanel Cron Jobs, frequência 6h |

**Arquivo de deploy:** `TVDCRM_upload.zip` (~117 KB) — contém todas as pastas `config/`, `includes/`, `pages/` e `index.php`.

---

## 10. Dependências Externas

| Dependência | Versão/URL | Uso |
|---|---|---|
| Tailwind CSS | CDN (latest) | Estilização completa |
| Pipedrive API | v1 (`api.pipedrive.com/v1`) | Sync de clientes e projetos |
| PHP cURL | Nativo (fallback: file_get_contents) | Requisições à API Pipedrive |

---

## 11. Fluxos de Negócio Principais

### Fluxo A — Entrada de equipamento novo
```
Recebimento físico
    → batch_entry.php (em lote) ou equipment/create.php (individual)
    → status: "Entrada"
    → Aguardar instalação ou alocação direta
```

### Fluxo B — Alocação a cliente
```
Equipamento em Estoque (entrada / aguardando / usado)
    → operations/saida.php
    → status: "Alocado"
    → Cliente vinculado ao equipamento
    → Sincronização automática via Pipedrive (Pontos Ativos Onsign)
```

### Fluxo C — Devolução
```
Equipamento Alocado
    → operations/retorno.php
    → Inspeção de periféricos
    → Condição: ok → "Equipamento Usado"
                manutencao → "Manutenção"
                descartar → "Baixado"
```

### Fluxo D — Pipedrive → CRM (atualização automática de Kanban)
```
Mudança de fase no Pipedrive (Board: Pontos de Exibição)
    → Cron ou sync manual
    → pipedrive_projects_sync.php
    → Extrai asset_tag do título do projeto
    → Mapeia phase_id → kanban_status
    → UPDATE equipment.kanban_status
    → INSERT kanban_history
```

### Fluxo E — Alerta de garantia
```
Usuário acessa /equipment/index.php
    → Sistema verifica todos os equipamentos com purchase_date
    → Se vencida (>12 meses) ou vencendo (≤30 dias):
        → Modal automático com lista dos equipamentos críticos
    → Badge colorido em todas as telas de equipamento
```

---

## 12. Melhorias Futuras Sugeridas

| Prioridade | Melhoria | Descrição |
|---|---|---|
| Alta | Renovação de garantia | Permitir registrar extensão de garantia por equipamento |
| Alta | Notificações por e-mail | Enviar alerta de garantia vencendo por e-mail ao admin |
| Alta | Exportação CSV/PDF | Exportar qualquer relatório para planilha ou PDF |
| Média | Dashboard por cliente | Página do cliente com KPIs de uptime e SLA |
| Média | QR Code / etiqueta | Gerar etiqueta com QR Code apontando para `view.php` |
| Média | App mobile | PWA ou app nativo para operações de campo |
| Média | Fotos do equipamento | Upload de imagens na ficha do equipamento |
| Baixa | Múltiplos boards Pipedrive | Suporte a boards além de "Pontos de Exibição" |
| Baixa | API REST pública | Expor endpoints para integrações futuras |
| Baixa | Dark mode | Alternância de tema claro/escuro |

---

*Documento gerado automaticamente com base no mapeamento completo do código-fonte em 27/02/2026.*
