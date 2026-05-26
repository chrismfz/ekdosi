//---------------------------------------------------------------------------

#include <vcl.h>
#pragma hdrstop

#include "FTest.h"
//---------------------------------------------------------------------------
#pragma package(smart_init)
#pragma resource "*.dfm"
TFrmTest *FrmTest;
//---------------------------------------------------------------------------
__fastcall TFrmTest::TFrmTest(TComponent* Owner)
	: TForm(Owner)
{
}
//---------------------------------------------------------------------------

HWND __fastcall TFrmTest::GetMDIClientHandle()
{
	HWND Result = NULL;

	TForm *Frm = dynamic_cast<TForm*>(Owner);
	if( Frm )
		Result = Frm->ClientHandle;
	if( (Result == NULL) && (Application->MainForm) )
		Result = Application->MainForm->ClientHandle;
	if( Result == NULL )
		throw EInvalidOperation("No Parent MDI Form");

	return Result;
}
//---------------------------------------------------------------------------
void __fastcall TFrmTest::CreateWindowHandle(const TCreateParams &Params)
{
	FMDIClientHandle = GetMDIClientHandle();

	MDICREATESTRUCT MDICreateStruct = {0};
	MDICreateStruct.szClass = Params.WinClassName;
	MDICreateStruct.szTitle = Params.Caption;
	MDICreateStruct.hOwner = ::GetModuleHandle(NULL);
	MDICreateStruct.x = Params.X;
	MDICreateStruct.y = Params.Y;
	MDICreateStruct.cx = Params.Width;
	MDICreateStruct.cy = Params.Height;
	MDICreateStruct.style = Params.Style;
	MDICreateStruct.lParam = (LPARAM) Params.Param;

	WindowHandle = (HWND) SendMessage(FMDIClientHandle, WM_MDICREATE, 0, (LPARAM)&MDICreateStruct);
	FFormState = FFormState << fsCreatedMDIChild;
}
//---------------------------------------------------------------------------
void __fastcall TFrmTest::DestroyWindowHandle()
{
	if( FFormState.Contains(fsCreatedMDIChild) )
		SendMessage(FMDIClientHandle, WM_MDIDESTROY, (WPARAM) Handle, 0);
	else
		TForm::DestroyWindowHandle();

	FMDIClientHandle = NULL;
}
//---------------------------------------------------------------------------