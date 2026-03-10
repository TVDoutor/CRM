# PRD — S8 Conect CRM
## Product Requirements Document

**Produto:** S8 Conect CRM — Controle de Equipamentos  
**Versão do documento:** 2.0  
**Data:** 10/03/2026  
**Responsável:** S8 Conect  
**URL de produção:** https://crm.tvdoutor.com.br  
**Stack:** PHP 8.3 · MySQL 5.7 · Tailwind CSS · Chart.js · Material Symbols · PDO · HostGator

---

## 1. Visão Geral do Produto

O **S8 Conect CRM** é um sistema web de gestão de equipamentos de exibição digital (players, TVs, monitores) alocados a clientes. Ele centraliza o ciclo de vida completo de cada equipamento — da entrada no estoque até a devolução ou baixa — e sincroniza automaticamente dados de clientes e projetos com o Pipedrive, CRM externo já utilizado pela equipe.

### Problema resolvido
A S8 Conect gerencia centenas de equipamentos distribuídos em clientes ("pontos de exibição"). Sem um sistema próprio, o controle era feito de forma fragmentada entre planilhas e o Pipedrive, sem visibilidade do status real de cada equipamento, datas de garantia, histórico de movimentações e responsabilidades por cliente.

### Proposta de valor
- Rastreabilidade total de cada equipamento, do recebimento à baixa
- Integração bidirecional com Pipedrive para eliminar retrabalho de cadastro
- Kanban visual alinhado com o processo operacional real da empresa
- Alertas automáticos de garantia (12 meses a partir da data de compra)
- Controle de acesso por perfil para segurança operacional
- Dashboard analítico com KPIs, gráficos e alertas em tempo real
- UX profissional com Material Symbols, loading states e design responsivo
- Recuperação de senha segura via e-mail
- Deploy automatizado via FTP com script PowerShell

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
| Recuperação de senha | Sim (sem login) | Sim (sem login) | Sim (sem login) |

---

## 3. Arquitetura do Sistema

```
crm.tvdoutor.com.br/
├── index.php                  → Gateway (redirect login ou dashboard)
├── config/
│   ├── database.php           → Conexão PDO singleton + BASE_URL dinâmica
│   ├── pipedrive.php          → Token, constantes, mapeamentos e funções da API Pipedrive
│   ├── migrate_projects.sql   → DDL das tabelas de projetos Pipedrive
│   ├── migrate_v2.sql         → DDL de migrações v2
│   ├── migrate_v3.sql         → DDL de migrações v3
│   └── migrate_password_reset.sql → DDL tabela password_resets
├── includes/
│   ├── auth.php               → Sessão, autenticação, CSRF, controle de roles
│   ├── helpers.php            → Funções utilitárias globais (warranty, badges, audit, kanban, MAC)
│   ├── navbar.php             → Sidebar responsiva com visibilidade por role
│   └── pipedrive_push.php     → Push seletivo de dados ao Pipedrive (fases do Kanban)
└── pages/
    ├── login.php              → Tela de login com show/hide senha
    ├── logout.php             → Encerramento de sessão
    ├── forgot_password.php    → Solicitação de recuperação de senha
    ├── reset_password.php     → Redefinição de senha via token
    ├── dashboard.php          → Dashboard analítico com KPIs e gráficos
    ├── kanban.php             → Quadro Kanban drag-and-drop
    ├── equipment/             → CRUD + entrada em lote + lotes
    ├── clients/               → CRUD de clientes com KPIs
    ├── operations/            → Saída, retorno (redesenhado), histórico
    ├── pipedrive/             → Painéis de integração + diagnóstico
    ├── reports/               → 6 relatórios
    ├── users/                 → Gestão de usuários (admin only) com KPIs
    ├── profile/               → Perfil do usuário logado
    └── api/                   → Endpoints JSON internos, sync Pipedrive e alertas de garantia
```

**~60 arquivos PHP** · Sem framework · Sem dependências npm · Deploy automatizado via FTP (PowerShell)

---

## 4. Banco de Dados

### 4.1 Diagrama de entidades (simplificado)

```
users
  └─ cria/movimenta ──→ equipment
  └─ registra ──→ audit_log
  └─ registra ──→ kanban_history
  └─ solicita ──→ password_resets

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
| title | VARCHAR(255) | Título completo (ex: "P3209 - Cliente \| 597C") |
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

#### `password_resets`
| Coluna | Tipo | Notas |
|---|---|---|
| id | INT PK | |
| user_id | INT FK | → users (ON DELETE CASCADE) |
| token | VARCHAR(64) UNIQUE | Token seguro (hex, 32 bytes) |
| expires_at | DATETIME | Validade de 1 hora |
| used_at | DATETIME | NULL = não usado, preenchido ao redefinir |
| created_at | DATETIME | DEFAULT CURRENT_TIMESTAMP |

---

## 5. Módulos do Sistema

### 5.1 Dashboard

**Objetivo:** Visão geral analítica instantânea do estoque, movimentações e indicadores-chave.

**KPIs (cards interativos):**
- Em Estoque (equipamentos com status: entrada, aguardando_instalacao, equipamento_usado)
- Alocados (status: alocado)
- Em Manutenção
- Total Geral

**Seções:**
- **Gráfico de distribuição por status** (Chart.js — doughnut)
- **Gráfico de movimentações por mês** (Chart.js — barras)
- **Tabela de estoque por modelo** (quantidade nova + usada)
- **Top 10 clientes** com mais equipamentos alocados
- **Últimas 10 movimentações** (data, tipo, cliente, etiquetas, responsável)
- **Alertas de atenção:**
  - Equipamentos em manutenção há mais de 30 dias
  - Garantias vencendo nos próximos 30 dias
- **Painel de sincronização Pipedrive** (última sync de clientes e projetos)
- **Ações rápidas:** links diretos para saída, devolução, entrada de lote e Kanban

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

**Transições:** Todas as 9 fases permitem movimentação livre entre si via drag-and-drop. Não há restrição de fluxo — o usuário pode mover qualquer card para qualquer coluna.

**Mapeamento Pipedrive → Kanban:**

| Fase no Pipedrive (Board: Pontos de Exibição) | Status no CRM |
|---|---|
| Entrada | `entrada` |
| Pontos Ativos Onsign | `alocado` |
| Offline +31 Dias | `licenca_removida` |
| Encaminhado ao CS - Sem Licença | `aguardando_instalacao` |
| Processo de Cancelamento | `processo_devolucao` |

**Sincronização seletiva com Pipedrive:**
Ao mover um card no Kanban, se existir um projeto Pipedrive vinculado ao equipamento, a fase correspondente é atualizada automaticamente no Pipedrive (push seletivo via `pipedrive_push.php`). Se não houver mapeamento de fase ou projeto, apenas o CRM é atualizado.

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
- KPIs: Total, Em Estoque, Alocados, Manutenção
- Tabela paginada (30 por página)
- Filtros: busca livre (etiqueta/SN/MAC), status Kanban, condição, tipo de contrato, modelo, lote
- Colunas: Etiqueta, Modelo, S/N, Condição, Status Kanban, Garantia, Tipo, Cliente, Lote, Ações
- **Modal automático de alertas de garantia** ao entrar na página (se houver equipamentos críticos)
- Ações por linha: Ver (todos) · Editar (admin/manager)
- Botões de cabeçalho: + Novo (admin/manager) · Entrada de Lote (todos)
- Material Symbols para ícones e ações

#### 5.3.2 Cadastro individual (`/equipment/create.php`)
**Acesso:** admin, manager

**Campos obrigatórios:** asset_tag, model_id, entry_date, purchase_date, contract_type  
**Campos opcionais:** serial_number, mac_address, batch, notes  
**Campo de status:** kanban_status inicial (entrada ou aguardando_instalacao)

**UX:** Formulário organizado em seções com ícones, loading state no submit, feedback visual de erros.

**Fluxo pós-cadastro:**
1. INSERT em `equipment`
2. INSERT em `kanban_history` (from=NULL, to='entrada')
3. INSERT em `audit_log`
4. Redirect para ficha do equipamento

#### 5.3.3 Edição (`/equipment/edit.php`)
**Acesso:** admin, manager (exclusão somente admin)

Mesmos campos do cadastro. Exclusão com modal de confirmação por digitação do asset_tag. Antes de deletar: remove kanban_history, equipment_notes, audit_log e registros de pipedrive_projects vinculados. Loading state no submit.

#### 5.3.4 Ficha completa (`/equipment/view.php`)
**Acesso:** todos

**Seções:**
- Header: asset_tag, modelo, badges de condição / status kanban / contrato / garantia
- Dados técnicos: S/N, MAC, data de entrada, lote, data de compra, vencimento da garantia
- Cliente atual com link para ficha do cliente
- Linha do Tempo: histórico completo de mudanças de status com data, responsável e observação (Material Symbols)
- Devoluções: checklist de periféricos (fonte, HDMI, controle) e condição registrada
- Notas internas: adicionar via AJAX (`POST /api/add_note.php`)
- Botão Editar (admin/manager)
- Etiqueta para impressão com o nome "S8 Conect CRM"

#### 5.3.5 Entrada em lote (`/equipment/batch_entry.php`)
**Acesso:** todos

Cadastra múltiplos equipamentos em uma única operação atômica (transação SQL). Campos comuns ao lote: batch, entry_date, purchase_date, model_id. Por equipamento: asset_tag, serial_number, mac_address. Geração dinâmica de linhas via JS. Validação de duplicatas intra-lote e contra o banco.

**Suporte a leitor de código de barras:**
- Ao preencher MAC Address (12 dígitos hex via `input` event ou Enter), foco pula automaticamente para Número de Série
- Ao pressionar Enter no Número de Série, foco pula para MAC da próxima linha
- Ao pressionar Enter no último campo, abre modal de confirmação:
  - **Salvar**: confirma o lote
  - **Voltar**: retorna para editar
  - **Cancelar**: limpa os dados e sai
- Previne submissão acidental do formulário ao pressionar Enter
- Auto-select do conteúdo ao receber foco (facilita substituição via scanner)

#### 5.3.6 Lotes (`/equipment/batches.php`)
**Acesso:** todos

Listagem e busca de lotes cadastrados com quantidades e detalhes.

---

### 5.4 Clientes

**Objetivo:** Cadastro e consulta dos clientes ("pontos de exibição") que recebem os equipamentos.

#### Listagem (`/clients/index.php`)
- **KPIs:** Total Cadastrados, Ativos, Com Equip. Alocado
- Filtros: busca (nome/código/CNPJ), status ativo/inativo
- Colunas: Código, Nome, CNPJ, Telefone, Cidade/UF, Equipamentos Ativos, Status, Ações
- Paginação com First/Last page
- Material Symbols para ações e filtros

#### Ficha do cliente (`/clients/view.php`)
- **KPIs:** Equipamentos Ativos, Média Dias Alocado, Total Dias Acumulados, Uptime (% Ativos)
- Dados cadastrais completos com badge de status
- Equipamentos ativos (com dias alocados, condição, tipo)
- Projetos Pipedrive vinculados (com link direto e status)
- Histórico completo de todos os equipamentos já alocados

#### Cadastro/Edição (`/clients/create.php` · `/clients/edit.php`)
**Acesso:** admin, manager

**Campos:** client_code *, name *, contact_name, cnpj, phone, email, address, city, state, notes, is_active

**UX:** Formulários organizados em seções ("Identificação", "Contato", "Endereço") com ícones Material Symbols, loading state no submit.

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

Registra retorno de equipamentos alocados. Redesenhado com UX profissional completa.

**KPIs (filtráveis por clique):**
- Total de equipamentos elegíveis
- Alocados
- Licença Removida
- Processo de Devolução

**Filtro inteligente:** busca por nome do cliente, código, MAC ou player tag.

**Layout por cliente:** Cards agrupados por cliente, cada card exibindo:
- Tag/MAC proeminente
- Badge colorido de status Kanban (Material Symbols)
- Badge de condição
- Modelo do equipamento
- Dias com o cliente (com alerta por cores: verde < 90, amarelo < 180, vermelho ≥ 180)

**Seleção e devolução:**
- Botão "Selecionar todos" por grupo de cliente
- Ao selecionar, expande seção de acessórios (fonte, HDMI, controle) e condição (ok / manutenção / descartar — botões coloridos)
- Footer fixo com contagem, data/hora, notas gerais e botão "Confirmar Devolução"
- Modal de confirmação antes da submissão

**Pré-condição:** Equipamento com status `alocado`, `licenca_removida` ou `processo_devolucao`.

**Por equipamento devolvido:**
- Periféricos devolvidos (fonte, HDMI, controle) — checkboxes
- Condição após retorno:
  - `ok` → equipamento vai para `equipamento_usado`
  - `manutencao` → vai para `manutencao`
  - `descartar` → vai para `baixado`

#### 5.5.3 Histórico de Operações (`/operations/history.php`)
**Acesso:** todos

Histórico paginado de todas as operações com Material Symbols para tipos de operação.

**Filtros:** período (data), tipo (ENTRADA/SAIDA/RETORNO/KANBAN_MOVE/etc), usuário responsável.

**Colunas:** Data, Tipo, Cliente, Qtd, Etiquetas, Responsável, Observações.

---

### 5.6 Relatórios

**Acesso geral:** todos os usuários logados (exceto Auditoria por Usuário: somente admin)

| Relatório | Arquivo | Descrição |
|---|---|---|
| Estoque Atual | `stock.php` | Equipamentos disponíveis (entrada/aguardando/usado). Filtros: condição, modelo, lote. Subtotais por modelo (novo/usado). |
| Entradas e Saídas | `entries_exits.php` | Operações no período. Filtros: data de/até, tipo. KPIs por tipo + tabela detalhada. |
| Histórico Equipamento | `by_equipment.php` | Timeline completa de um equipamento (busca por etiqueta ou S/N). Material Symbols na timeline. |
| Relatório por Cliente | `by_client.php` | Equipamentos ativos e histórico completo de um cliente. Busca por código ou nome. Usa LEFT JOIN para incluir equipamentos sem histórico Kanban (importados via Pipedrive). |
| Auditoria por Usuário | `by_user.php` | Todas as ações de um usuário no sistema. Filtros: usuário, período, tipo de ação. Exibe IP, entidade, old/new values. **Acesso: somente admin.** |
| Relatórios (índice) | `index.php` | Painel central com acesso a todos os relatórios via cards com ícones Material Symbols. |

**Exportação:** Relatório por Cliente e Estoque Atual suportam exportação CSV com nomes de arquivo sanitizados.

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

**Automação:** Cron job a cada 8 horas (3x ao dia)
```
0 */8 * * * /usr/local/bin/php /home2/tvdout68/crm.tvdoutor.com.br/pages/api/pipedrive_sync.php cron
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

**Cron automático** (3x ao dia):
```
0 */8 * * * /usr/local/bin/php /home2/tvdout68/crm.tvdoutor.com.br/pages/api/pipedrive_projects_sync.php cron
```

#### 5.7.3 Push seletivo ao Pipedrive (`/includes/pipedrive_push.php`)
Quando um equipamento é movido no Kanban do CRM, se `PIPE_PUSH_KANBAN_MOVE_ENABLED = true`, o sistema atualiza a fase do projeto correspondente no Pipedrive. Se não houver mapeamento de fase ou projeto vinculado, apenas o CRM é atualizado (sem erro).

#### 5.7.4 Diagnóstico (`/pipedrive/diagnostic.php`)
Painel de diagnóstico da integração Pipedrive com testes de conectividade e status.

---

### 5.8 Usuários

**Acesso:** somente admin

**KPIs:** Total de Usuários, Ativos, Administradores.

**Funcionalidades:**
- Listar todos os usuários com avatares (iniciais), busca em tempo real
- Criar novo usuário (nome *, e-mail *, telefone, perfil, senha *)
- Editar usuário (todos os campos, inclusive desativar conta)
- Alterar senha de qualquer usuário
- Excluir usuário (modal de confirmação por e-mail) — não permite excluir a si mesmo
- Proteção: não é possível rebaixar o único administrador do sistema
- Material Symbols para roles e ações
- Loading states em formulários
- Formulários organizados em seções ("Dados Pessoais", "Segurança")

---

### 5.9 Perfil do Usuário

**Acesso:** qualquer usuário logado (somente sobre si mesmo)

**Dois formulários:**
1. **Dados do perfil:** nome, telefone (e-mail: readonly)
2. **Alteração de senha:** senha atual + nova senha + confirmação

> E-mail só pode ser alterado por um administrador via `/users/edit.php`.

---

### 5.10 Autenticação e Recuperação de Senha

#### Login (`/pages/login.php`)
- Branding "S8 CONECT CRM" com subtítulo "Controle de Equipamentos"
- Campos com ícones Material Symbols (mail, lock)
- Show/hide password com ícone toggle (visibility/visibility_off)
- Loading state no botão ao submeter
- Link "Esqueceu a senha?" para recuperação
- Proteção CSRF
- Rodapé com copyright S8 Conect

#### Recuperação de senha (`/pages/forgot_password.php`)
- Formulário para informar e-mail cadastrado
- Gera token seguro (64 chars hex, `random_bytes(32)`)
- Validade do token: **1 hora**
- Remove tokens anteriores não utilizados do mesmo usuário
- Envia e-mail HTML profissional com link de redefinição
- Mensagem genérica de sucesso (não revela se e-mail existe — segurança)
- Loading state no botão de envio

#### Redefinição de senha (`/pages/reset_password.php`)
- Validação do token: existência, expiração, uso prévio
- Saudação personalizada com nome do usuário
- Campos: nova senha + confirmação com show/hide
- **Indicador visual de força da senha** (barra colorida: Fraca → Regular → Média → Boa → Forte)
- Validação: mínimo 6 caracteres, senhas devem coincidir
- Token marcado como usado após redefinição bem-sucedida
- Tela de sucesso com botão para ir ao login
- Tela de erro com link para solicitar nova recuperação
- Proteção CSRF

---

### 5.11 Sistema de Garantia

**Regra de negócio:** Todo equipamento tem garantia de 12 meses a partir da `purchase_date`.

**Estados possíveis:**

| Status | Condição | Badge | Cor |
|---|---|---|---|
| `ok` | Mais de 30 dias até o vencimento | Garantia | Verde |
| `vencendo` | 0–30 dias até o vencimento | Vencendo | Laranja |
| `vencida` | Já passou a data de vencimento | Vencida | Vermelho |
| `sem_data` | `purchase_date` não preenchida | — Sem data | Cinza |

**Suporte a extensão de garantia:** Campo `extended_until` permite registrar renovação.

**Presença no sistema:**
- Badge na listagem de equipamentos
- Badge na ficha do equipamento (`view.php`)
- Badge nos cards do Kanban (apenas vencendo/vencida)
- **Modal automático** na listagem ao entrar na página (se houver equipamentos críticos)

**Alerta por e-mail:** Script de cron (`/api/warranty_alert_email.php`) envia relatório HTML aos administradores com equipamentos vencidos e vencendo.

---

## 6. APIs Internas (JSON)

| Endpoint | Método | Acesso | Descrição |
|---|---|---|---|
| `/api/kanban_move.php` | POST JSON | Todos (logados) | Move equipamento entre colunas do Kanban. Valida CSRF. Push seletivo ao Pipedrive. Retorna `{success, from, to}` |
| `/api/add_note.php` | POST JSON | Todos (logados) | Adiciona nota interna a um equipamento. Retorna `{success, id}` |
| `/api/delete_note.php` | POST JSON | Todos (logados) | Remove nota interna |
| `/api/edit_note.php` | POST JSON | Todos (logados) | Edita nota interna existente |
| `/api/save_client.php` | POST JSON | Todos (logados) | Cadastro rápido de cliente (modal na saída de equipamento). Retorna `{success, id}` |
| `/api/get_equipment.php` | GET | Todos (logados) | Busca equipamento por ID ou texto. Retorna array JSON |
| `/api/search_batches.php` | GET | Todos (logados) | Busca lotes de equipamentos |
| `/api/update_equipment_fields.php` | POST JSON | admin, manager | Atualiza campos de equipamentos |
| `/api/upload_photo.php` | POST | admin, manager | Upload de fotos de equipamentos |
| `/api/export_csv.php` | GET | Todos (logados) | Exportação de dados em CSV (nome sanitizado) |
| `/api/pipedrive_sync.php` | POST/GET | admin, manager / cron | Sincroniza clientes do Pipedrive |
| `/api/pipedrive_projects_sync.php` | POST/GET | admin, manager / cron | Sincroniza projetos e atualiza Kanban |
| `/api/pipedrive_import_equipment.php` | POST/GET | admin, manager / cron | Importa novos equipamentos do Pipedrive |
| `/api/pipedrive_update_equipment.php` | POST/GET | admin, manager / cron | Atualiza dados de equipamentos existentes |
| `/api/warranty_alert_email.php` | GET | cron | Envia alerta de garantia por e-mail aos admins |

**Segurança:** Todos os endpoints POST requerem token CSRF válido na sessão. Endpoints de cron aceitam GET com `cron_key` (parâmetro ou header `X-Cron-Key`).

---

## 7. Segurança

| Mecanismo | Implementação |
|---|---|
| Autenticação | Sessão PHP com `session_regenerate_id(true)` no login |
| Senhas | Armazenadas com `password_hash(PASSWORD_DEFAULT)` (bcrypt) |
| Recuperação de senha | Token seguro de uso único com expiração de 1h |
| CSRF | Token de sessão validado em todo POST, incluindo login (helpers `csrfField()` / `csrfValid()` / `csrfValidate()`) |
| Autorização | `requireRole(array $roles)` em cada página/endpoint protegido |
| XSS | `htmlspecialchars()` em todos os outputs via helper `sanitize()` |
| SQL Injection | PDO com prepared statements em todas as queries |
| Input Validation | Whitelisting de valores válidos (enums, status, roles) |
| Filename Sanitization | Nomes de arquivo de exportação CSV sanitizados contra path traversal |
| Auditoria | Toda ação significativa registrada em `audit_log` com IP, usuário, old/new values |
| Proteção de cron | Chave secreta `PIPEDRIVE_CRON_KEY` obrigatória, suporte a header `X-Cron-Key` |
| HTTP Response Splitting | Sanitização de headers em exportações |

---

## 8. UX e Design System

### Identidade visual
- **Marca:** S8 Conect (login: "S8 CONECT CRM")
- **Cor brand principal:** `#1B4F8C` (azul institucional)
- **Cor brand dark:** `#153d6f`
- **Cor brand light:** `#D6E4F0`

### Iconografia
**Material Symbols Outlined** (Google Fonts) em todo o sistema, substituindo emojis. Carregado via CDN em todas as páginas:
```html
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
```

### Framework CSS
Tailwind CSS (CDN) com configuração customizada de cores brand.

### Bibliotecas visuais
- **Chart.js** (CDN) — Gráficos no Dashboard (doughnut, barras)

### Padrões de UX

| Padrão | Descrição |
|---|---|
| KPIs | Cards com métricas-chave no topo das listagens (Dashboard, Clientes, Usuários, Equipamentos, Devolução) |
| Loading states | Botões de submit desativam e exibem spinner "progress_activity" + texto "Salvando..."/"Entrando..." |
| Empty states | Mensagens amigáveis com ícone quando não há dados |
| Badges coloridos | Status Kanban, condição, garantia, role de usuário — todos com cores consistentes |
| Flash messages | Sucesso/erro/info/warning entre páginas com cores e ícones |
| Modais de confirmação | Ações destrutivas (excluir) e operações de lote (devolução, entrada) |
| Tooltips | Badges de garantia com data exata e dias restantes |
| Filtros inteligentes | Busca em tempo real client-side em listagens |
| Formulários em seções | Agrupamento lógico com ícones (ex: "Dados Pessoais", "Segurança", "Endereço") |
| Indicador de força de senha | Barra visual com 5 níveis na redefinição de senha |
| Show/hide password | Toggle de visibilidade em todos os campos de senha |

### Responsividade

**Breakpoints:**
- Mobile: sidebar oculta (hamburguer + overlay), colunas da tabela ocultadas progressivamente
- Tablet (`md`): colunas secundárias aparecem
- Desktop (`lg`/`xl`): layout completo, sidebar fixa

**Navbar responsiva:**
- Desktop: sidebar fixa à esquerda, largura `w-64`
- Mobile: drawer lateral com translate, overlay escuro, fechamento ao clicar fora

**Kanban:**
- Desktop: scroll horizontal entre colunas
- Mobile: accordion por status (lista)

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
| Deploy | Automatizado via `deploy.ps1` (FTP direto) |
| Cron | cPanel Cron Jobs, frequência 8h (3x ao dia) |

### Deploy automatizado (`deploy.ps1`)

Script PowerShell que realiza deploy completo via FTP:

**Funcionalidades:**
- Lê credenciais de `.deploy-config` (não versionado)
- Upload direto de todos os arquivos PHP (`config/`, `includes/`, `pages/`, `index.php`)
- Criação automática de diretórios remotos inexistentes
- Barra de progresso com percentual e contagem
- Relatório final com tempo total e quantidade de arquivos
- Flag `-SkipZip` para pular geração de arquivo ZIP

**Configuração (`.deploy-config`):**
```ini
FTP_HOST=crm.tvdoutor.com.br
FTP_USER=usuario@crm.tvdoutor.com.br
FTP_PASS=senha
FTP_PORT=21
REMOTE_PATH=/
DEPLOY_KEY=chave_secreta
```

**Uso:**
```powershell
.\deploy.ps1           # Deploy completo
.\deploy.ps1 -SkipZip  # Pula geração do ZIP
```

---

## 10. Dependências Externas

| Dependência | Versão/URL | Uso |
|---|---|---|
| Tailwind CSS | CDN (latest) | Estilização completa |
| Chart.js | CDN (latest) | Gráficos no Dashboard |
| Material Symbols Outlined | Google Fonts CDN | Iconografia em todo o sistema |
| Pipedrive API | v1 (`api.pipedrive.com/v1`) | Sync de clientes e projetos |
| PHP cURL | Nativo (fallback: file_get_contents) | Requisições à API Pipedrive |
| PHP mail() | Nativo | E-mails de garantia e recuperação de senha |

---

## 11. Fluxos de Negócio Principais

### Fluxo A — Entrada de equipamento novo
```
Recebimento físico
    → batch_entry.php (em lote, com suporte a leitor de código de barras)
      ou equipment/create.php (individual)
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
Equipamento Alocado / Licença Removida / Processo Devolução
    → operations/retorno.php (interface redesenhada com cards)
    → Seleção de equipamentos por cliente
    → Inspeção de periféricos (fonte, HDMI, controle)
    → Condição: ok → "Equipamento Usado"
                manutencao → "Manutenção"
                descartar → "Baixado"
```

### Fluxo D — Pipedrive → CRM (atualização automática de Kanban)
```
Mudança de fase no Pipedrive (Board: Pontos de Exibição)
    → Cron (3x ao dia) ou sync manual
    → pipedrive_projects_sync.php
    → Extrai asset_tag do título do projeto
    → Mapeia phase_id → kanban_status
    → UPDATE equipment.kanban_status
    → INSERT kanban_history
```

### Fluxo E — CRM → Pipedrive (push seletivo)
```
Movimentação no Kanban do CRM (drag-and-drop)
    → kanban_move.php
    → Verifica se existe projeto Pipedrive vinculado
    → Mapeia kanban_status → phase_id Pipedrive
    → PUT /projects/{id} via API Pipedrive
    → Se não houver mapeamento: apenas CRM atualizado
```

### Fluxo F — Alerta de garantia
```
Usuário acessa /equipment/index.php
    → Sistema verifica todos os equipamentos com purchase_date
    → Se vencida (>12 meses) ou vencendo (≤30 dias):
        → Modal automático com lista dos equipamentos críticos
    → Badge colorido em todas as telas de equipamento

Cron job periódico
    → warranty_alert_email.php
    → E-mail HTML aos administradores com equipamentos críticos
```

### Fluxo G — Recuperação de senha
```
Usuário na tela de login
    → Clica "Esqueceu a senha?"
    → forgot_password.php: informa e-mail
    → Sistema gera token (64 chars, validade 1h)
    → E-mail HTML com link de redefinição
    → reset_password.php: nova senha + confirmação
    → Token invalidado após uso
    → Redirect para login
```

---

## 12. Changelog de Melhorias (v1.0 → v2.0)

| Data | Melhoria | Impacto |
|---|---|---|
| 02/2026 | Sistema base (v1.0) | Lançamento inicial |
| 03/2026 | Show/hide password no login | UX |
| 03/2026 | Transição livre entre todas as fases do Kanban | Funcionalidade |
| 03/2026 | Push seletivo ao Pipedrive ao mover no Kanban | Integração |
| 03/2026 | Suporte a leitor de código de barras na entrada de lote | UX/Operacional |
| 03/2026 | Modal de confirmação de salvamento na entrada de lote | UX |
| 03/2026 | Auditoria de segurança completa (CSRF, XSS, SQL Injection, sanitização) | Segurança |
| 03/2026 | Dashboard analítico com KPIs, gráficos Chart.js e alertas | UX/Analytics |
| 03/2026 | Cron job de sincronização Pipedrive a cada 8h (3x/dia) | Automação |
| 03/2026 | Redesign completo da página Devolução/Retorno | UX |
| 03/2026 | Material Symbols Outlined em todas as páginas (substituindo emojis) | UX/Branding |
| 03/2026 | Cor brand unificada #1B4F8C em todo o sistema | Branding |
| 03/2026 | Loading states em todos os formulários | UX |
| 03/2026 | KPIs nas listagens de Clientes e Usuários | UX/Analytics |
| 03/2026 | Redesign de formulários com seções e ícones | UX |
| 03/2026 | Paginação aprimorada com First/Last page | UX |
| 03/2026 | Deploy automatizado via FTP (`deploy.ps1`) | DevOps |
| 03/2026 | Rebrand: TV Doutor → S8 Conect | Branding |
| 03/2026 | Recuperação de senha via e-mail com token seguro | Funcionalidade/Segurança |
| 03/2026 | Indicador de força de senha na redefinição | UX/Segurança |
| 03/2026 | Alerta de garantia por e-mail (cron) | Funcionalidade |
| 03/2026 | Correção: relatório por cliente incluindo equipamentos sem histórico Kanban | Bug fix |

---

## 13. Melhorias Futuras Sugeridas

| Prioridade | Melhoria | Descrição |
|---|---|---|
| Alta | QR Code / etiqueta | Gerar etiqueta com QR Code apontando para `view.php` |
| Alta | App mobile | PWA ou app nativo para operações de campo |
| Média | Múltiplos boards Pipedrive | Suporte a boards além de "Pontos de Exibição" |
| Média | Dashboard por período | Filtros de data nos gráficos e KPIs do dashboard |
| Média | Notificações in-app | Sistema de notificações internas (garantia, sync, etc.) |
| Baixa | API REST pública | Expor endpoints para integrações futuras |
| Baixa | Dark mode | Alternância de tema claro/escuro |
| Baixa | Exportação PDF | Exportar relatórios em formato PDF |

---

*Documento atualizado em 10/03/2026 com base no mapeamento completo do código-fonte (v2.0).*
