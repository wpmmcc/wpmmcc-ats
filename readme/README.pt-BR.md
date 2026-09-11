# WPMMCC ATS — Plugin WordPress multilíngue

**WordPress multilíngue na prática: escanear, relacionar, traduzir.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | **Português (Brasil)** | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS é um plugin WordPress que transforma um site monolíngue em multilíngue. Ele escaneia plugins de conteúdo, temas e menus em busca de campos traduzíveis, cria regras de tradução reutilizáveis, gerencia relações de site entre idiomas e traz um editor completo de tradução manual dentro do wp-admin. A tradução automática é possível com o cliente complementar WPTSALL Client — sem conta, sem licença, sem dependência de fornecedor.

## Links
- Project website — https://www.wpmm.cc/
- Documentation and usage help (English / 简体中文) — https://www.wpmm.cc/docs/
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc-ats/
- Companion client repository — https://github.com/wpmmcc/wptsall-client

## Funcionalidades
- Escaneamento de conteúdo — detecta campos traduzíveis em posts, taxonomias, meta, plugins de conteúdo de terceiros e temas
- Relações de site — liga idioma de origem e destino, incluindo sites virtuais com prefixo próprio de URL
- Editor de tradução manual — traduza posts e campos inteiramente dentro do admin do WordPress
- Regras de tradução — um mesmo conjunto de regras para o editor manual e a API do cliente
- API de cliente Protocol v2 — permite que o WPTSALL Client pegue tarefas e escreva os resultados
- Pacotes de idioma — inglês embutido; chinês simplificado em andamento (veja abaixo)

## Requisitos
- WordPress 6.2 ou superior
- PHP 7.4 ou superior
- Sem conta, assinatura ou chave de licença

## Instalação
1. Baixe o ZIP da versão mais recente na página Releases deste repositório (ou compacte o código-fonte você mesmo)
2. No wp-admin, vá em Plugins → Adicionar novo → Enviar plugin, envie o ZIP e ative
3. Abra o menu WPMMCC ATS no wp-admin

## Início rápido (tradução manual, sem cliente)
1. Escaneie seus plugins de conteúdo ou crie um conjunto de regras nas páginas de administração do WPMMCC ATS
2. Adicione uma relação de site: escolha idioma de origem e destino — um site virtual recebe prefixo próprio de URL, ex. /en_us/
3. Abra um post no editor de tradução manual e traduz; ao salvar, o site de destino é atualizado

## Tradução automática
Para tradução automática, instale o cliente complementar WPTSALL Client (WebUI ou Desktop). Ele conecta direto ao seu site com um token de dispositivo, pega tarefas de tradução, chama o provedor configurado e escreve os resultados de volta. Repositório do cliente: https://github.com/wpmmcc/wptsall-client

## Atualização
- As atualizações são instaladas pelo atualizador padrão do WordPress — Painel → Atualizações, ou na página de Plugins. Não são necessárias etapas manuais
- A atualização nunca altera seus dados de tradução: tabelas, sites virtuais, memória de tradução, terminologia e configurações permanecem intactos
- As notas de cada versão estão na seção Changelog do arquivo readme.txt

## Desinstalação
- Desative o plugin na página de Plugins e depois o exclua
- A partir da versão 2.1.3, ao excluir o plugin seus dados de tradução são mantidos por padrão (tabelas, posts/termos traduzidos, memória de tradução, terminologia, pacotes de idioma), portanto reinstalar restaura tudo
- Para uma limpeza completa, ative "Excluir dados ao desinstalar" nas configurações do plugin antes de excluir. Configurações do plugin, transientes e tarefas agendadas são sempre removidos de qualquer forma

## Componentes de código aberto
- Nenhum código de terceiros é embutido: PHP puro sobre APIs do núcleo do WordPress (REST, WPDB/dbDelta, cron, gettext), com páginas de administração em JavaScript puro e jQuery fornecido pelo WordPress
- As traduções da interface vêm do sistema de tradução do WordPress.org (GlotPress) — translate.wordpress.org
- A detecção de campos lê dados de plugins de conteúdo de terceiros como WooCommerce, Elementor, ACF e Yoast SEO; esses projetos não são embutidos nem modificados
- Coexiste sem problemas com os plugins multilíngues WPML e Polylang — nenhum código desses projetos é utilizado

## Languages
O inglês é o idioma de origem embutido. Um pacote de chinês simplificado (zh_CN) está em andamento em languages/; depois que o catálogo for compilado, escolha o idioma do site em Configurações → Geral e o WordPress o carrega automaticamente. Para adicionar outro idioma, traduza languages/wpmmcc-ats.pot no seu editor de PO favorito e contribua.

## License
GPL-2.0-or-later. Veja [LICENSE](../LICENSE).

