<?php

namespace Tests\Feature;

use App\Models\Embarcacao;
use App\Models\Funcionario;
use App\Models\Servico;
use App\Models\User;
use App\Services\ServicoService;
use App\Services\VerificacaoItens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServicoVerificacaoItensTest extends TestCase
{
    use RefreshDatabase;

    private function verificacao(string $atividade = 'lavagem'): array
    {
        return [
            'equipamentos' => array_fill_keys(array_keys(VerificacaoItens::EQUIPAMENTOS), 'funcionamento_ok'),
            'atividade' => $atividade,
        ];
    }

    private function payload(): array
    {
        return [
            'embarcacao_id' => Embarcacao::factory()->create()->id,
            'funcionario_id' => Funcionario::factory()->create()->id,
            'data_execucao' => '2026-11-01',
            'verificacao_itens' => $this->verificacao(),
        ];
    }

    public static function atividades(): array
    {
        return [['lavagem'], ['limpeza']];
    }

    #[DataProvider('atividades')]
    public function test_registra_classificacoes_e_atividade_preservando_observacao_opcional(string $atividade): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        $payload['verificacao_itens'] = $this->verificacao($atividade);
        $payload['verificacao_itens']['equipamentos']['buzina'] = 'nao_se_aplica';
        $payload['verificacao_itens']['equipamentos']['motor'] = 'com_defeito';
        $payload['observacao'] = "Motor falha após ligar.\nCliente ciente.";

        $this->actingAs($admin)->post(route('servicos.store'), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $servico = Servico::query()->firstOrFail();
        $this->assertSame($payload['verificacao_itens'], $servico->verificacao_itens);
        $this->assertSame($payload['observacao'], $servico->observacao);
        $this->assertSame(Servico::STATUS_AGUARDANDO_VISTORIA, $servico->status);
        $this->assertSame($admin->id, $servico->created_by_user_id);

        unset($payload['observacao']);
        $this->post(route('servicos.store'), $payload)->assertSessionHasNoErrors();
        $this->assertNull(Servico::query()->latest('id')->firstOrFail()->observacao);

        $this->get(route('servicos.index'))->assertInertia(fn ($page) => $page
            ->where('servicos.0.verificacao_itens', $payload['verificacao_itens']));
    }

    public static function invalidos(): array
    {
        $cases = [];
        foreach (array_keys(VerificacaoItens::EQUIPAMENTOS) as $codigo) {
            $cases[$codigo.' ausente'] = ['equipamentos.'.$codigo, '__ausente'];
            $cases[$codigo.' vazio'] = ['equipamentos.'.$codigo, ''];
        }

        return $cases + [
            'classificacao invalida' => ['equipamentos.motor', 'bom'],
            'duas classificacoes' => ['equipamentos.motor', ['com_defeito', 'funcionamento_ok']],
            'equipamento desconhecido' => ['equipamentos.outro', 'funcionamento_ok'],
            'sem atividade' => ['atividade', '__ausente'],
            'atividade vazia' => ['atividade', ''],
            'ambas atividades' => ['atividade', ['lavagem', 'limpeza']],
            'atividade invalida' => ['atividade', 'outra'],
            'observacao por item' => ['observacoes', ['motor' => 'falha']],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_rejeita_criacao_e_edicao_invalidas_sem_mutacao(string $path, mixed $value): void
    {
        $admin = User::factory()->admin()->create();
        $payload = $this->payload();
        $servico = Servico::factory()->create(['verificacao_itens' => $this->verificacao()]);
        $before = $servico->fresh()->getAttributes();
        if ($value === '__ausente') {
            data_forget($payload['verificacao_itens'], $path);
        } else {
            data_set($payload['verificacao_itens'], $path, $value);
        }

        $this->actingAs($admin)->postJson(route('servicos.store'), $payload)->assertUnprocessable();
        $this->putJson(route('servicos.update', $servico), [
            'verificacao_itens' => $payload['verificacao_itens'],
            'observacao' => 'Não pode persistir',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('servicos', 1);
        $this->assertSame($before, $servico->fresh()->getAttributes());
    }

    public function test_nao_aceita_payload_antigo_para_novos_registros_nem_null_na_edicao(): void
    {
        $payload = $this->payload();
        unset($payload['verificacao_itens']);
        $payload['descricao'] = ['Lavagem'];
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('servicos.store'), $payload)->assertUnprocessable();
        $servico = Servico::factory()->create(['verificacao_itens' => $this->verificacao()]);
        $this->putJson(route('servicos.update', $servico), ['verificacao_itens' => null])
            ->assertUnprocessable();
        $this->assertSame($this->verificacao(), $servico->fresh()->verificacao_itens);
    }

    public function test_legado_preserva_descricao_e_nao_exige_classificacao_para_observacao_ou_status(): void
    {
        $servico = Servico::factory()->create(['descricao' => ['Motor', 'Limpeza']]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put(route('servicos.update', $servico), ['observacao' => 'Detalhe geral'])
            ->assertSessionHasNoErrors();
        $this->put(route('servicos.update', $servico), ['status' => 'concluido'])->assertSessionHasNoErrors();
        $servico->refresh();
        $this->assertNull($servico->verificacao_itens);
        $this->assertSame(['Motor', 'Limpeza'], $servico->descricao);
        $this->assertSame('Detalhe geral', $servico->observacao);
        $this->assertSame('concluido', $servico->status);
    }

    public function test_classifica_legado_explicitamente_sem_reescrever_descricao_ou_observacao(): void
    {
        $servico = Servico::factory()->create(['observacao' => 'Original']);
        $descricao = $servico->descricao;
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('servicos.update', $servico), ['verificacao_itens' => $this->verificacao()])
            ->assertSessionHasNoErrors();
        $servico->refresh();
        $this->assertSame($descricao, $servico->descricao);
        $this->assertSame('Original', $servico->observacao);
        $this->assertSame($this->verificacao(), $servico->verificacao_itens);
    }

    public function test_edita_classificacao_e_conclui_sem_apagar_dados_e_sem_reabrir(): void
    {
        $servico = Servico::factory()->create(['verificacao_itens' => $this->verificacao()]);
        $novo = $this->verificacao('limpeza');
        $novo['equipamentos']['motor'] = 'com_defeito';
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('servicos.update', $servico), ['verificacao_itens' => $novo])->assertSessionHasNoErrors();
        $this->put(route('servicos.update', $servico), ['status' => 'concluido'])->assertSessionHasNoErrors();
        $this->putJson(route('servicos.update', $servico), ['status' => 'aguardando_vistoria'])
            ->assertUnprocessable();
        $novo['equipamentos']['motor'] = 'funcionamento_ok';
        $this->put(route('servicos.update', $servico), ['verificacao_itens' => $novo])->assertSessionHasNoErrors();
        $this->assertSame($novo, $servico->fresh()->verificacao_itens);
        $this->assertSame('concluido', $servico->fresh()->status);
    }

    public function test_funcionario_edita_apenas_proprio_aberto_sem_alterar_status(): void
    {
        $funcionario = Funcionario::factory()->create();
        $user = User::factory()->create(['funcionario_id' => $funcionario->id]);
        $servico = Servico::factory()->create([
            'funcionario_id' => $funcionario->id,
            'verificacao_itens' => $this->verificacao(),
        ]);
        $novo = $this->verificacao('limpeza');
        $this->actingAs($user)->put(route('servicos.update', $servico), ['verificacao_itens' => $novo])
            ->assertSessionHasNoErrors();
        $this->assertSame($novo, $servico->fresh()->verificacao_itens);
        $this->assertSame('aguardando_vistoria', $servico->fresh()->status);
        $this->putJson(route('servicos.update', $servico), ['status' => 'concluido', 'verificacao_itens' => $this->verificacao()])->assertForbidden();
        $outro = Servico::factory()->create();
        $this->putJson(route('servicos.update', $outro), ['verificacao_itens' => $novo])->assertForbidden();
        $servico->update(['status' => 'concluido']);
        $this->putJson(route('servicos.update', $servico), ['verificacao_itens' => $this->verificacao()])->assertForbidden();
        $this->assertSame($novo, $servico->fresh()->verificacao_itens);
    }

    public function test_service_tambem_exige_classificacao_na_criacao(): void
    {
        $payload = $this->payload();
        unset($payload['verificacao_itens']);
        $this->expectException(ValidationException::class);
        app(ServicoService::class)->criar($payload, User::factory()->admin()->create());
    }

    public function test_migration_up_down_preserva_registro_legado(): void
    {
        $migration = require database_path('migrations/2026_11_01_120000_add_verificacao_itens_to_servicos_table.php');
        $migration->down();
        $funcionario = Funcionario::factory()->create();
        $embarcacao = Embarcacao::factory()->create();
        $id = DB::table('servicos')->insertGetId([
            'funcionario_id' => $funcionario->id,
            'embarcacao_id' => $embarcacao->id,
            'descricao' => json_encode(['Motor', 'Limpeza']),
            'data_execucao' => '2026-10-01',
            'status' => 'em_execucao',
            'observacao' => 'Registro original',
        ]);
        $before = (array) DB::table('servicos')->find($id);
        $migration->up();
        $after = (array) DB::table('servicos')->find($id);
        $this->assertNull($after['verificacao_itens']);
        unset($after['verificacao_itens']);
        $this->assertSame($before, $after);
        $migration->down();
        $this->assertSame($before, (array) DB::table('servicos')->find($id));
        $migration->up();
    }
}
