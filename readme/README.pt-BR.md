# WPMMCC ATS — Plugin WordPress multilíngue

**WordPress multilíngue na prática: escanear, relacionar, traduzir.**

[English](../README.md) | [简体中文](README.zh-CN.md) | [繁體中文](README.zh-TW.md) | [日本語](README.ja.md) | [한국어](README.ko.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | **Português (Brasil)** | [Italiano](README.it.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [हिन्दी](README.hi.md) | [Türkçe](README.tr.md) | [Tiếng Việt](README.vi.md) | [Bahasa Indonesia](README.id.md)

WPMMCC ATS é um plugin WordPress que transforma um site monolíngue em multilíngue. Ele escaneia plugins de conteúdo, temas e menus em busca de campos traduzíveis, cria regras de tradução reutilizáveis, gerencia relações de site entre idiomas e traz um editor completo de tradução manual dentro do wp-admin. A tradução automática é possível com o cliente complementar WPTSALL Client — sem conta, sem licença, sem dependência de fornecedor.

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

## Languages
O inglês é o idioma de origem embutido. Um pacote de chinês simplificado (zh_CN) está em andamento em languages/; depois que o catálogo for compilado, escolha o idioma do site em Configurações → Geral e o WordPress o carrega automaticamente. Para adicionar outro idioma, traduza languages/wpmmcc-ats.pot no seu editor de PO favorito e contribua.

## License
GPL-2.0-or-later. Veja [LICENSE](../LICENSE).

