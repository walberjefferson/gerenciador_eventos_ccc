import type { AtividadePublica, DiaEventoPublico, GrupoAtividadePublico } from '@/types/evento';
import type { ConflitoDeAtividades, SituacaoDaAtividade } from '@/types/inscricao';
import { computed, ref, type ComputedRef, type Ref } from 'vue';

/**
 * Espelha, na tela, as regras de escolha de atividades que o servidor ja
 * aplica (RN-03 a RN-09): minimo e maximo por bloco, bloco obrigatorio,
 * choque de horario, conflito declarado, faixa etaria e vaga esgotada.
 *
 * Isto aqui e conforto, nunca autoridade: serve para o participante entender
 * antes de tentar. Quem decide de verdade continua sendo o backend, e a
 * resposta 422 dele sempre manda.
 */

export interface OpcoesDeSelecao {
    dias: Ref<DiaEventoPublico[]> | ComputedRef<DiaEventoPublico[]>;
    conflitos: Ref<ConflitoDeAtividades[]> | ComputedRef<ConflitoDeAtividades[]>;
    /** Data de nascimento no formato AAAA-MM-DD, como vem do campo de data. */
    dataNascimento: Ref<string> | ComputedRef<string>;
}

export interface ProblemaDeSelecao {
    grupoId: number;
    mensagem: string;
}

/** Um pedaco AAAA-MM-DD de qualquer data ISO, sem passar por fuso horario. */
function apenasODia(iso: string): string {
    return iso.slice(0, 10);
}

/**
 * Idade completa que a pessoa tera no dia em que a atividade comeca — a mesma
 * conta que o backend faz em Atividade::idadeNaData().
 */
export function idadeNaData(dataNascimento: string, dataDaAtividade: string): number | null {
    const nascimento = apenasODia(dataNascimento);
    const atividade = apenasODia(dataDaAtividade);

    if (!/^\d{4}-\d{2}-\d{2}$/.test(nascimento) || !/^\d{4}-\d{2}-\d{2}$/.test(atividade)) {
        return null;
    }

    const [anoN, mesN, diaN] = nascimento.split('-').map(Number);
    const [anoA, mesA, diaA] = atividade.split('-').map(Number);

    let idade = anoA - anoN;

    if (mesA < mesN || (mesA === mesN && diaA < diaN)) {
        idade -= 1;
    }

    return idade;
}

/**
 * Duas atividades se sobrepoem quando uma comeca antes de a outra terminar,
 * dos dois lados. Limites que apenas se encostam — uma termina exatamente
 * quando a outra comeca — NAO se sobrepoem, e continuam permitidos.
 */
export function haChoqueDeHorario(a: AtividadePublica, b: AtividadePublica): boolean {
    const comecaA = Date.parse(a.comeca_em);
    const terminaA = Date.parse(a.termina_em);
    const comecaB = Date.parse(b.comeca_em);
    const terminaB = Date.parse(b.termina_em);

    if ([comecaA, terminaA, comecaB, terminaB].some((instante) => Number.isNaN(instante))) {
        return false;
    }

    return comecaA < terminaB && terminaA > comecaB;
}

function quantidade(numero: number): string {
    return numero === 1 ? '1 atividade' : `${numero} atividades`;
}

/** O minimo que vale de fato: um bloco obrigatorio pede ao menos uma escolha. */
export function minimoDoGrupo(grupo: GrupoAtividadePublico): number {
    return Math.max(grupo.min_selecoes, grupo.obrigatorio ? 1 : 0);
}

export function useSelecaoAtividades(opcoes: OpcoesDeSelecao) {
    const selecionadas = ref<number[]>([]);

    const grupos = computed<GrupoAtividadePublico[]>(() => opcoes.dias.value.flatMap((dia: DiaEventoPublico) => dia.grupos));

    const atividadePorId = computed<Map<number, AtividadePublica>>(() => {
        const mapa = new Map<number, AtividadePublica>();

        grupos.value.forEach((grupo) => grupo.atividades.forEach((atividade) => mapa.set(atividade.id, atividade)));

        return mapa;
    });

    const grupoDaAtividade = computed<Map<number, GrupoAtividadePublico>>(() => {
        const mapa = new Map<number, GrupoAtividadePublico>();

        grupos.value.forEach((grupo) => grupo.atividades.forEach((atividade) => mapa.set(atividade.id, grupo)));

        return mapa;
    });

    /**
     * O par vem normalizado do banco (menor id primeiro), entao guardamos os
     * dois sentidos: quem olha a atividade B precisa enxergar a A.
     */
    const conflitosPorAtividade = computed<Map<number, number[]>>(() => {
        const mapa = new Map<number, number[]>();

        const anotar = (de: number, para: number) => {
            const lista = mapa.get(de) ?? [];
            lista.push(para);
            mapa.set(de, lista);
        };

        opcoes.conflitos.value.forEach((conflito: ConflitoDeAtividades) => {
            anotar(conflito.atividade_a_id, conflito.atividade_b_id);
            anotar(conflito.atividade_b_id, conflito.atividade_a_id);
        });

        return mapa;
    });

    function estaSelecionada(atividadeId: number): boolean {
        return selecionadas.value.includes(atividadeId);
    }

    const atividadesSelecionadas = computed<AtividadePublica[]>(() =>
        selecionadas.value.map((id) => atividadePorId.value.get(id)).filter((atividade): atividade is AtividadePublica => atividade !== undefined),
    );

    function contagemDoGrupo(grupoId: number): number {
        const grupo = grupos.value.find((candidato) => candidato.id === grupoId);

        if (grupo === undefined) {
            return 0;
        }

        return grupo.atividades.filter((atividade) => estaSelecionada(atividade.id)).length;
    }

    function grupoAtingiuMaximo(grupo: GrupoAtividadePublico): boolean {
        return grupo.max_selecoes !== null && contagemDoGrupo(grupo.id) >= grupo.max_selecoes;
    }

    /** "2 de 2 selecionadas" — o aviso que explica por que o resto travou. */
    function rotuloDeContagem(grupo: GrupoAtividadePublico): string {
        const escolhidas = contagemDoGrupo(grupo.id);
        const palavra = escolhidas === 1 ? 'selecionada' : 'selecionadas';

        if (grupo.max_selecoes === null) {
            return `${escolhidas} ${palavra}`;
        }

        return `${escolhidas} de ${grupo.max_selecoes} ${palavra}`;
    }

    function choqueDeHorarioCom(atividade: AtividadePublica): AtividadePublica | null {
        return atividadesSelecionadas.value.find((outra) => outra.id !== atividade.id && haChoqueDeHorario(atividade, outra)) ?? null;
    }

    function conflitoDeclaradoCom(atividade: AtividadePublica): AtividadePublica | null {
        const impedidas = conflitosPorAtividade.value.get(atividade.id) ?? [];

        return atividadesSelecionadas.value.find((outra) => outra.id !== atividade.id && impedidas.includes(outra.id)) ?? null;
    }

    /** Frase da faixa etaria quando a idade na data da atividade nao cabe. */
    function foraDaFaixaEtaria(atividade: AtividadePublica): string | null {
        if (atividade.idade_minima === null && atividade.idade_maxima === null) {
            return null;
        }

        const idade = idadeNaData(opcoes.dataNascimento.value, atividade.comeca_em);

        if (idade === null) {
            return null;
        }

        if (atividade.idade_minima !== null && idade < atividade.idade_minima) {
            return `Indisponível — é para quem tem a partir de ${atividade.idade_minima} anos no dia da atividade.`;
        }

        if (atividade.idade_maxima !== null && idade > atividade.idade_maxima) {
            return `Indisponível — é para quem tem até ${atividade.idade_maxima} anos no dia da atividade.`;
        }

        return null;
    }

    /**
     * A situacao de uma atividade agora: se da para escolher e, quando nao da,
     * a frase que explica o motivo com o nome da atividade que atrapalha.
     */
    function situacaoDe(atividadeId: number): SituacaoDaAtividade {
        const atividade = atividadePorId.value.get(atividadeId);

        if (atividade === undefined) {
            return { selecionada: false, selecionavel: false, motivo: 'Esta atividade não está mais disponível.' };
        }

        // Ja escolhida: sempre da para desmarcar, senao o participante trava.
        if (estaSelecionada(atividadeId)) {
            return { selecionada: true, selecionavel: true, motivo: null };
        }

        if (atividade.esgotado) {
            return { selecionada: false, selecionavel: false, motivo: 'Esgotado' };
        }

        const idade = foraDaFaixaEtaria(atividade);

        if (idade !== null) {
            return { selecionada: false, selecionavel: false, motivo: idade };
        }

        const choque = choqueDeHorarioCom(atividade);

        if (choque !== null) {
            return { selecionada: false, selecionavel: false, motivo: `Indisponível — conflito de horário com ${choque.nome}` };
        }

        const conflito = conflitoDeclaradoCom(atividade);

        if (conflito !== null) {
            return { selecionada: false, selecionavel: false, motivo: `Indisponível — não pode ser escolhida junto com ${conflito.nome}` };
        }

        const grupo = grupoDaAtividade.value.get(atividadeId);

        if (grupo !== undefined && grupoAtingiuMaximo(grupo)) {
            return {
                selecionada: false,
                selecionavel: false,
                motivo: `Você já escolheu ${rotuloDeContagem(grupo)} neste bloco. Desmarque uma para trocar.`,
            };
        }

        return { selecionada: false, selecionavel: true, motivo: null };
    }

    function alternar(atividadeId: number): void {
        if (estaSelecionada(atividadeId)) {
            selecionadas.value = selecionadas.value.filter((id) => id !== atividadeId);

            return;
        }

        if (!situacaoDe(atividadeId).selecionavel) {
            return;
        }

        selecionadas.value = [...selecionadas.value, atividadeId];
    }

    function limpar(): void {
        selecionadas.value = [];
    }

    /**
     * Escolhas que deixaram de valer depois que outra coisa mudou — trocar a
     * data de nascimento, por exemplo. Some com elas em silencio seria pior:
     * a tela avisa e o participante escolhe de novo.
     */
    function descartarSelecoesInvalidas(): number[] {
        const descartadas: number[] = [];

        selecionadas.value.forEach((id) => {
            const atividade = atividadePorId.value.get(id);

            if (atividade === undefined || atividade.esgotado || foraDaFaixaEtaria(atividade) !== null) {
                descartadas.push(id);
            }
        });

        if (descartadas.length > 0) {
            selecionadas.value = selecionadas.value.filter((id) => !descartadas.includes(id));
        }

        return descartadas;
    }

    /** O que ainda falta para o passo da participacao estar completo. */
    const problemas = computed<ProblemaDeSelecao[]>(() =>
        grupos.value.flatMap((grupo) => {
            const minimo = minimoDoGrupo(grupo);
            const escolhidas = contagemDoGrupo(grupo.id);

            if (minimo === 0 || escolhidas >= minimo) {
                return [];
            }

            const mensagem =
                grupo.max_selecoes !== null && grupo.max_selecoes === minimo
                    ? `Em "${grupo.nome}", escolha ${quantidade(minimo)} para continuar.`
                    : `Em "${grupo.nome}", escolha ao menos ${quantidade(minimo)} para continuar.`;

            return [{ grupoId: grupo.id, mensagem }];
        }),
    );

    const podeAvancar = computed<boolean>(() => problemas.value.length === 0);

    const totalSelecionadas = computed<number>(() => selecionadas.value.length);

    return {
        selecionadas,
        atividadesSelecionadas,
        alternar,
        limpar,
        descartarSelecoesInvalidas,
        estaSelecionada,
        situacaoDe,
        contagemDoGrupo,
        grupoAtingiuMaximo,
        rotuloDeContagem,
        minimoDoGrupo,
        problemas,
        podeAvancar,
        totalSelecionadas,
    };
}
